<?php
/**
 * All Orders - View All Ongoing Orders
 */

// Check auth first (before any output) - auth.php already starts session
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();

// Include header
// Include header only if not AJAX
if (!isset($_GET['ajax'])) {
    require_once __DIR__ . '/includes/header.php';
}


$pageTitle = 'All Orders';

// Get search parameter
$search = trim($_GET['search'] ?? '');
$date = trim($_GET['date'] ?? '');
if (empty($search) && empty($date)) {
    $date = getBusinessDate();
}

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query
global $pdo;

try {
    // Build WHERE clause for search
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
                "o.order_id LIKE ?",
                "COALESCE(o.customer_name, u.name) LIKE ?",
                "COALESCE(o.order_code, '') LIKE ?",
                "CONCAT('ORD-', LPAD(o.id, 5, '0')) LIKE ?",
                "o.id LIKE ?"
            ];
            $currentParams = [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm];

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

    // Date Filter - handles 4:00 AM business day logic
    if (!empty($date)) {
        $range = getBusinessDayRange($date);
        $where[] = "o.created_at BETWEEN ? AND ?";
        $params[] = $range['start'];
        $params[] = $range['end'];
    }

    // Show only fully completed orders (payment confirmed by rider)
    $where[] = "o.status = 'completed'";

    // Note: This implicitly filters out cancelled, pending, confirmed, etc.

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    // Get total count (for pagination)
    $countQuery = "SELECT COUNT(*) as total FROM orders o";
    if (!empty($search)) {
        $countQuery .= " LEFT JOIN users u ON o.user_id = u.id";
    }
    $countQuery .= " " . $whereClause;

    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($params);
    $totalOrders = $countStmt->fetch()['total'];
    $totalPages = ceil($totalOrders / $perPage);

    // Prepare subquery joins
    $subqueryJoins = "";
    if (!empty($search)) {
        $subqueryJoins = " LEFT JOIN users u ON o.user_id = u.id";
    }

    // Get all orders with user info
    $limitParams = array_merge($params, [$perPage, $offset]);
    $stmt = $pdo->prepare("
        SELECT o.*, 
               u.name as user_name,
               u.phone as user_phone,
               u.delivery_location as user_delivery_location,
               u.street_location as user_street_location,
               COALESCE(o.customer_name, u.name) as display_name,
               COALESCE(o.customer_phone, u.phone) as display_phone,
               COALESCE(NULLIF(o.delivery_address, ''), NULLIF(CONCAT_WS(', ', NULLIF(u.delivery_location, ''), NULLIF(u.street_location, '')), ''), 'N/A') as display_location,
               (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count,
               (SELECT SUM(line_total) FROM order_items WHERE order_id = o.id) as items_total,
               r.username as rider_name
        FROM orders o
        INNER JOIN (
            SELECT o.id FROM orders o
            $subqueryJoins
            $whereClause
            ORDER BY o.created_at DESC 
            LIMIT ? OFFSET ?
        ) as sub ON o.id = sub.id
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN riders r ON o.rider_id = r.id
        ORDER BY o.created_at DESC
    ");
    $stmt->execute($limitParams);
    $orders = $stmt->fetchAll();

    // Stats array removed for optimization
    $stats = [];

} catch (PDOException $e) {
    error_log("All Orders error: " . $e->getMessage());
    $error_msg = $e->getMessage();
    $orders = [];
    $totalOrders = 0;
    $totalPages = 1;
}
?>

<?php if (!isset($_GET['ajax'])): ?>
    <div class="dashboard-controls" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; gap: 20px; flex-wrap: wrap;">
        <div class="search-container" style="max-width: 100%; flex: 1;">
            <form method="GET" action="" class="search-form">
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
                        <a href="allorder.php" class="search-clear" title="Clear search">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M18 6L6 18M6 6l12 12" />
                            </svg>
                        </a>
                    <?php endif; ?>
                </div>
                <input type="date" name="date" value="<?php echo htmlspecialchars($date); ?>" onchange="this.form.submit()"
                    style="max-width: 160px; margin-left: 10px; padding: 0 16px; border-radius: 14px; cursor: pointer; border: 1.5px solid #6366f1; background: #eef2ff; color: #4338ca; font-weight: 700; font-family: 'Outfit', 'Inter', sans-serif; font-size: 14px; outline: none; box-shadow: 0 2px 4px rgba(99,102,241,0.1); height: 44px;"
                    title="Filter by date">
                <button type="submit" class="search-btn">Search</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- Reuse the order list display from admin_dashboard, without the filters logic -->
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
        <p>There are no orders in the system.</p>
        <?php if (!empty($error_msg)): ?>
            <p style="color: red; margin-top: 10px; font-weight: bold; background: #fee2e2; padding: 10px; border-radius: 8px;">
                DEBUG ERROR: <?php echo htmlspecialchars($error_msg); ?></p>
        <?php endif; ?>
    </div>
<?php else: ?>
    <?php foreach ($orders as $order): ?>
        <!-- Copied Order Card Structure from admin_dashboard.php -->
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
                    $statusClass = 'pending';
                    $statusText = 'Pending';

                    if ($status === 'cancelled') {
                        $statusClass = 'cancelled';
                        $statusText = 'Order Cancelled';
                    } elseif ($status === 'confirmed' || $status === 'preparing') {
                        $statusClass = 'confirmed';
                        $statusText = 'Confirmed and Preparing';
                    } elseif ($status === 'ready') {
                        $statusClass = 'ready';
                        $statusText = 'Ready for Delivery';
                    } elseif ($status === 'received') {
                        $statusClass = 'received';
                        $statusText = 'Food Collected';
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
                        <div class="essential-label">LOCATION</div>
                        <div class="essential-value">
                            <span><?php echo htmlspecialchars($order['display_location'] ?? 'N/A'); ?></span>
                            <?php if (!empty($order['location_lat']) && !empty($order['location_lng'])): ?>
                                <div style="font-size: 11px; color: #64748b; font-weight: 600; margin-top: 2px;">
                                    <?php echo htmlspecialchars($order['location_lat'] . ', ' . $order['location_lng']); ?>
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
                            if (empty($payStatus))
                                $payStatus = 'unpaid';

                            // Online pending check
                            $isOnlinePending = ($payStatus === 'pending' && in_array($payMethod, ['esewa', 'khalti', 'connectips', 'online', 'online_cod']));

                            if ($payStatus === 'paid') {
                                $payClass = 'payment-paid';
                                $payText = 'Paid';
                            } elseif ($isOnlinePending) {
                                $payClass = 'payment-pending';
                                $payText = 'Payment Pending';
                            } elseif ($payStatus === 'failed' || $payStatus === 'cancelled') {
                                $payClass = 'payment-unpaid';
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
                            $displayMethod = $payMethod ? ucfirst($payMethod) : 'N/A';
                            if (strtoupper($payMethod) === 'COD') {
                                $displayMethod = 'Cash In Delivery';
                            }

                            // Sub-method for COD/SPLIT
                            $subMethod = '';
                            if (strtoupper($payMethod) === 'COD' || strtoupper($payMethod) === 'SPLIT') {
                                $cashAmt = floatval($order['paid_amount_cash'] ?? 0);
                                $onlineAmt = floatval($order['paid_amount_online'] ?? 0);
                                if ($cashAmt > 0 && $onlineAmt > 0) {
                                    $subMethod = '(Split)';
                                } elseif ($onlineAmt > 0) {
                                    $subMethod = '(Online)';
                                } elseif ($cashAmt > 0) {
                                    $subMethod = '(Cash)';
                                }
                            }

                            if ($payStatus === 'failed' || $payStatus === 'cancelled') {
                                echo '<div style="font-size: 13px; font-weight: 800; color: #000000;">' . $displayMethod . '</div>';
                                if ($subMethod) echo '<div style="font-size: 11px; color: #64748b; font-weight: 700; margin-top: 1px;">' . $subMethod . '</div>';
                                echo '<div style="font-size: 11px; font-weight: 700; color: #dc2626; margin-top:2px;">Failed</div>';
                            } elseif ($isOnlinePending) {
                                echo '<div style="font-size: 13px; font-weight: 800; color: #000000;">' . $displayMethod . '</div>';
                                if ($subMethod) echo '<div style="font-size: 11px; color: #64748b; font-weight: 700; margin-top: 1px;">' . $subMethod . '</div>';
                                echo '<div style="font-size: 11px; font-weight: 700; color: #d97706; margin-top:2px;">Processing...</div>';
                            } elseif ($payStatus === 'paid') {
                                if (strtoupper($payMethod) === 'ESEWA') {
                                    echo '<div style="font-size: 13px; font-weight: 800; color: #000000; display:flex; align-items:center; gap:6px;">
                                            <img src="../assets/esewalogo.jpg" alt="eSewa" style="height:28px; width:auto; border-radius:4px;">
                                            ' . $displayMethod . ' <span style="color: #059669; font-weight:700;">Paid</span>
                                          </div>';
                                } else {
                                    echo '<div style="font-size: 13px; font-weight: 800; color: #000000;">' . $displayMethod . ' <span style="color: #059669; font-weight:700;">Paid</span></div>';
                                }
                                if ($subMethod) echo '<div style="font-size: 11px; color: #64748b; font-weight: 700; margin-top: -2px; margin-bottom: 2px;">' . $subMethod . '</div>';
                            } else {
                                echo '<div style="font-size: 13px; font-weight: 800; color: #000000;">' . $displayMethod . '</div>';
                                if ($subMethod) echo '<div style="font-size: 11px; color: #64748b; font-weight: 700; margin-top: 1px;">' . $subMethod . '</div>';
                            }
                            ?>
                        </div>
                    </div>
                    <div class="essential-info essential-total">
                        <div class="essential-label">TOTAL</div>
                        <div class="essential-value total-amount">Rs. <?php echo number_format($order['total'], 2); ?></div>
                    </div>
                </div>

                <div class="order-details-row">
                    <span
                        class="detail-badge badge-<?php echo strtolower($order['service_type']); ?>"><?php echo ucfirst($order['service_type']); ?></span>
                    <span class="detail-badge"><?php echo $order['item_count']; ?> item(s)</span>
                    <?php if ($order['service_type'] === 'pickup' && $order['pickup_time']): ?>
                        <span class="detail-badge">Pickup: <?php echo htmlspecialchars($order['pickup_time']); ?></span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($order['rider_id'])): ?>
                    <div
                        style="margin-top: 12px; padding: 10px 14px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; display: flex; align-items: center; gap: 10px;">
                        <div
                            style="background: #10b981; color: white; padding: 6px; border-radius: 7px; display:flex; align-items:center; justify-content:center;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="1" y="3" width="15" height="13"></rect>
                                <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
                                <circle cx="5.5" cy="18.5" r="2.5"></circle>
                                <circle cx="18.5" cy="18.5" r="2.5"></circle>
                            </svg>
                        </div>
                        <div style="flex:1;">
                            <div
                                style="font-size:10px; font-weight:800; color:#059669; text-transform:uppercase; letter-spacing:0.6px;">
                                Delivery Rider</div>
                            <div style="font-size:14px; font-weight:700; color:#111827;">
                                <?php echo htmlspecialchars($order['rider_display_name'] ?? $order['rider_name'] ?? 'Assigned Rider'); ?>
                            </div>
                        </div>
                        <span
                            style="background:#dcfce7; color:#16a34a; font-size:10px; font-weight:700; padding:3px 8px; border-radius:8px; border:1px solid #bbf7d0;">DELIVERED</span>
                    </div>
                <?php endif; ?>

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
                    <button class="btn btn-sm btn-secondary view-order-btn" data-order-id="<?php echo $order['id']; ?>">
                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none">
                            <path
                                d="M10 3C6 3 2.73 5.11 1 8.5C2.73 11.89 6 14 10 14C14 14 17.27 11.89 19 8.5C17.27 5.11 14 3 10 3ZM10 12.5C8.067 12.5 6.5 10.933 6.5 9C6.5 7.067 8.067 5.5 10 5.5C11.933 5.5 13.5 7.067 13.5 9C13.5 10.933 11.933 12.5 10 12.5Z"
                                stroke="currentColor" stroke-width="1.5" />
                        </svg>
                        View Details
                    </button>

                </div>

                <div class="status-update-group">
                    <div class="status-display">
                        <span class="status-label">Status:</span>
                        <span class="status-text">
                            <?php
                            $currentStatus = strtolower(trim($order['status'] ?? 'pending'));
                            if ($currentStatus === 'confirmed' || $currentStatus === 'preparing') {
                                echo 'Confirmed and Preparing';
                            } elseif ($currentStatus === 'ready') {
                                echo 'Ready for Delivery';
                            } elseif ($currentStatus === 'received') {
                                echo 'Food Collected';
                            } elseif ($currentStatus === 'cancelled') {
                                echo 'Order Cancelled';
                            } else {
                                echo ucfirst($currentStatus);
                            }
                            ?>
                        </span>
                    </div>
                    <?php
                    $orderStatusForDelete = strtolower(trim($order['status'] ?? ''));
                    // Only show delete button for cancelled orders
                    if ($orderStatusForDelete === 'cancelled'):
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

    <?php
    // Build base URL for pagination maintaining search, date AND branch
    $queryParams = $_GET;
    // Ensure ajax is not in the base URL for these links
    unset($queryParams['ajax']);
    
    // Page is handled per link
    unset($queryParams['page']);

    $queryString = http_build_query($queryParams);
    $baseUrl = '?' . ($queryString ? $queryString . '&' : '');
    ?>
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>" class="pagination-btn">Previous</a>
            <?php endif; ?>

            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $i; ?>"
                    class="pagination-btn <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>" class="pagination-btn">Next</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php if (!isset($_GET['ajax']))
    echo '</div>'; ?>

<?php if (isset($_GET['ajax']))
    exit; ?>

<!-- Payment Incomplete Modal -->

<!-- Order Detail Modal (Required for "View Details" button to work) -->
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

<script>
    // Notification function
    function showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `notification notification-${type}`;
        notification.textContent = message;
        document.body.appendChild(notification);

        setTimeout(() => notification.classList.add('show'), 100);
        setTimeout(() => {
            notification.classList.remove('show');
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }

    // Modern Confirmation Dialog
    function showConfirmDialog(title, message, onConfirm, onCancel = null) {
        // Remove existing dialog if any
        const existing = document.getElementById('confirmDialog');
        if (existing) {
            existing.remove();
        }

        const dialog = document.createElement('div');
        dialog.id = 'confirmDialog';
        dialog.className = 'confirm-dialog-overlay';
        dialog.innerHTML = `
        <div class="confirm-dialog">
            <div class="confirm-dialog-header">
                <h3 class="confirm-dialog-title">${title}</h3>
                <button class="confirm-dialog-close" aria-label="Close">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path d="M15 5L5 15M5 5l10 10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            <div class="confirm-dialog-body">
                <div class="confirm-dialog-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none">
                        <path d="M12 9v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <p class="confirm-dialog-message">${message}</p>
            </div>
            <div class="confirm-dialog-footer">
                <button class="confirm-btn confirm-btn-no">Cancel</button>
                <button class="confirm-btn confirm-btn-yes confirm-btn-delete">Delete</button>
            </div>
        </div>
    `;

        document.body.appendChild(dialog);
        document.documentElement.classList.add('modal-open');
        document.body.classList.add('modal-open');

        // Animate in
        setTimeout(() => dialog.classList.add('active'), 10);

        // Close handlers
        const closeDialog = () => {
            dialog.classList.remove('active');
            document.documentElement.classList.remove('modal-open');
            document.body.classList.remove('modal-open');
            setTimeout(() => dialog.remove(), 300);
            if (onCancel) onCancel();
        };

        // Yes button
        dialog.querySelector('.confirm-btn-yes').addEventListener('click', () => {
            dialog.classList.remove('active');
            document.documentElement.classList.remove('modal-open');
            document.body.classList.remove('modal-open');
            setTimeout(() => {
                dialog.remove();
                if (onConfirm) onConfirm();
            }, 300);
        });

        // No button and close button
        dialog.querySelector('.confirm-btn-no').addEventListener('click', closeDialog);
        dialog.querySelector('.confirm-dialog-close').addEventListener('click', closeDialog);

        // Close on backdrop click
        dialog.addEventListener('click', (e) => {
            if (e.target === dialog) {
                closeDialog();
            }
        });

        // Close on Escape key
        const escapeHandler = (e) => {
            if (e.key === 'Escape') {
                closeDialog();
                document.removeEventListener('keydown', escapeHandler);
            }
        };
        document.addEventListener('keydown', escapeHandler);
    }

    // Status changes logic
    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('status-select')) {
            const select = e.target;
            const orderId = select.dataset.orderId;
            const newStatus = select.value;
            const orderCard = select.closest('.order-card');

            if (!newStatus) return;

            select.disabled = true;

            fetch('api/update_order_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `order_id=${orderId}&status=${encodeURIComponent(newStatus)}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload(); // Simple reload to reflect changes
                    } else {
                        alert('Error updating status');
                        select.disabled = false;
                    }
                });
        }
    });

    // View Details logic relies on existing admin.js or inline scripts from dashboard. 
    // We'll include the modal logic here just in case.
    document.addEventListener('click', function (e) {
        if (e.target.closest('.view-order-btn')) {
            const btn = e.target.closest('.view-order-btn');
            const orderId = btn.dataset.orderId;
            const modal = document.getElementById('orderDetailModal');
            const content = document.getElementById('orderDetailContent');

            modal.classList.add('active');
            document.documentElement.classList.add('modal-open');
            document.body.classList.add('modal-open');

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

        if (e.target.closest('.modal-close') || e.target.classList.contains('modal')) {
            document.getElementById('orderDetailModal').classList.remove('active');
            document.documentElement.classList.remove('modal-open');
            document.body.classList.remove('modal-open');
        }

        // Delete order handler
        if (e.target.closest('.delete-order-btn')) {
            const btn = e.target.closest('.delete-order-btn');
            e.stopPropagation();
            const orderId = btn.dataset.orderId;
            const orderCard = btn.closest('.order-card');

            // Use custom confirmation dialog
            showConfirmDialog(
                'Delete Order',
                'Are you sure you want to delete this order? This action cannot be undone.',
                () => {
                    // Yes - proceed with deletion
                    btn.disabled = true;
                    const originalHTML = btn.innerHTML;
                    btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" stroke-dasharray="32" stroke-dashoffset="32"><animate attributeName="stroke-dasharray" dur="2s" values="0 32;16 16;0 32;0 32" repeatCount="indefinite"/><animate attributeName="stroke-dashoffset" dur="2s" values="0;-16;-32;-32" repeatCount="indefinite"/></circle></svg> Deleting...';

                    fetch('api/delete_order.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `order_id=${orderId}`
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                orderCard.style.transition = 'all 0.3s ease';
                                orderCard.style.opacity = '0';
                                orderCard.style.transform = 'translateX(-100%)';
                                setTimeout(() => {
                                    orderCard.remove();
                                    window.location.reload();
                                }, 300);
                            } else {
                                showNotification(data.error || 'Failed to delete order', 'error');
                                btn.disabled = false;
                                btn.innerHTML = originalHTML;
                            }
                        })
                        .catch(err => {
                            console.error('Error:', err);
                            showNotification('An error occurred', 'error');
                            btn.disabled = false;
                            btn.innerHTML = originalHTML;
                        });
                }
            );
        }

        // Clear Order Handler (Button Removed)
    });
</script>

<?php
if (isset($_GET['ajax'])) {
    exit;
}
?>

<script>
    // Live Auto-Refresh
    document.addEventListener('DOMContentLoaded', function () {
        let isUpdating = false;

        setInterval(function () {
            if (isUpdating) return;
            if (document.hidden) return;

            const params = new URLSearchParams(window.location.search);
            params.set('ajax', '1');

            isUpdating = true;
            fetch('allorder.php?' + params.toString(), {
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
                    if (!response.ok) throw new Error('HTTP check failed');
                    return response.text();
                })
                .then(html => {
                    const list = document.querySelector('.orders-list');
                    if (list) {
                        if (html.trim().indexOf('415 Unsupported Media Type') === -1) {
                            if (list.innerHTML.trim() !== html.trim()) {
                                list.innerHTML = html;
                            }
                        }
                    }
                    isUpdating = false;
                })
                .catch(err => {
                    isUpdating = false;
                });
        }, 5000); // Polling restricted to 5 seconds to reduce systemic lag
    });
</script>


<style>
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
    .print-receipt-btn {
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
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>