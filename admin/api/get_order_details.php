<?php
/**
 * API: Get Order Details
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$orderId = intval($_GET['order_id'] ?? 0);

if (!$orderId) {
    echo json_encode(['success' => false, 'error' => 'Invalid order ID']);
    exit;
}

try {
    global $pdo;

    // Get order with user information and verify branch ownership
    $selectedBranchId = getAdminBranchId();
    $whereParams = [$orderId];
    $whereSql = "WHERE o.id = ?";

    if ($selectedBranchId) {
        $whereSql .= " AND o.restaurant_id = ?";
        $whereParams[] = $selectedBranchId;
    }

    $stmt = $pdo->prepare("
        SELECT o.*, 
               u.name as user_name,
               u.phone as user_phone,
               u.email as user_email,
               u.delivery_location as user_delivery_location,
               u.street_location as user_street_location,
               COALESCE(o.location_lat, u.location_lat) as location_lat,
               COALESCE(o.location_lng, u.location_lng) as location_lng,
               COALESCE(o.customer_name, u.name) as display_name,
               COALESCE(o.customer_phone, u.phone) as display_phone,
               COALESCE(o.customer_email, u.email) as display_email,
               r.location_lat as rider_lat,
               r.location_lng as rider_lng,
               r.username as rider_name
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN riders r ON o.rider_id = r.id
        $whereSql
    ");
    $stmt->execute($whereParams);
    $order = $stmt->fetch();

    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }

    // Get order items
    $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll();

    // Get payment transaction details (for eSewa/Online payments)
    $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE order_id = ? AND status = 'COMPLETE' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$orderId]);
    $txn = $stmt->fetch();

    // Generate HTML
    ob_start();
    ?>
    <div class="order-detail-premium">
        <!-- Premium Header -->
        <div class="od-header">
            <div class="od-header-left">
                <div class="od-id-wrapper">
                    <h1 class="od-id">#ORD-<?php echo str_pad($order['id'], 5, '0', STR_PAD_LEFT); ?></h1>
                    <span class="od-service-tag service-<?php echo strtolower($order['service_type']); ?>">
                        <?php echo ucfirst($order['service_type']); ?>
                    </span>
                </div>
                <div class="od-meta">
                    <span class="od-date">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                        <?php echo date('M d, Y', strtotime($order['created_at'])); ?>
                    </span>
                    <span class="od-time">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                        <?php echo date('h:i A', strtotime($order['created_at'])); ?>
                    </span>
                </div>
            </div>
            <div class="od-header-right">
                <?php
                $status = strtolower(trim($order['status']));
                $statusClass = 'pending';
                $statusText = 'Pending';

                if ($status === 'cancelled') {
                    $statusClass = 'cancelled';
                    $statusText = 'Order Cancelled';
                } elseif ($status === 'confirmed' || $status === 'preparing') {
                    $statusClass = 'confirmed';
                    $statusText = 'Confirmed & Preparing';
                } elseif ($status === 'ready') {
                    $statusClass = 'ready';
                    $statusText = 'Ready for Delivery';
                } elseif ($status === 'received') {
                    $statusClass = 'received';
                    $statusText = 'Order Received';
                } elseif ($status === 'completed') {
                    $statusClass = 'completed';
                    $statusText = 'Completed';
                } else {
                    $statusClass = $status;
                    $statusText = ucfirst($status);
                }
                ?>
                <div class="od-status status-<?php echo $statusClass; ?>">
                    <div class="od-status-dot"></div>
                    <?php echo $statusText; ?>
                </div>
            </div>
        </div>

        <div class="od-content-grid">
            <!-- Left Column: Items & Totals -->
            <div class="od-main-col">
                <!-- Order Items -->
                <div class="od-section">
                    <h3 class="od-section-title">Order Items</h3>
                    <div class="od-items-table-wrapper">
                        <div class="od-items-header">
                            <div class="od-col-index">#</div>
                            <div class="od-col-item">Item Details</div>
                            <div class="od-col-qty">S.Qty</div>
                            <div class="od-col-price">Price</div>
                            <div class="od-col-total">Total</div>
                        </div>

                        <div class="od-items-scroll-area">
                            <?php $index = 1;
                            foreach ($items as $item): ?>
                                <div class="od-item-row">
                                    <div class="od-col-index"><?php echo $index++; ?></div>
                                    <div class="od-col-item">
                                        <div class="od-item-name"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                        <?php if ($item['item_description']): ?>
                                            <div class="od-item-desc"><?php echo htmlspecialchars($item['item_description']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="od-col-qty">x<?php echo $item['quantity']; ?></div>
                                    <div class="od-col-price">Rs. <?php echo number_format($item['unit_price'], 2); ?></div>
                                    <div class="od-col-total">Rs. <?php echo number_format($item['line_total'], 2); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Totals -->
                <div class="od-totals-wrapper">
                    <div class="od-total-row">
                        <span>Subtotal</span>
                        <span>Rs. <?php echo number_format($order['subtotal'] ?? $order['total'], 2); ?></span>
                    </div>
                    <?php if ($order['delivery_fee'] > 0): ?>
                        <div class="od-total-row">
                            <span>Delivery Fee</span>
                            <span>Rs. <?php echo number_format($order['delivery_fee'], 2); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="od-total-row final">
                        <span>Total Amount</span>
                        <span>Rs. <?php echo number_format($order['total'], 2); ?></span>
                    </div>
                </div>

                <?php if ($order['notes']): ?>
                    <div class="od-section od-notes-section">
                        <h3 class="od-section-title">Special Instructions</h3>
                        <div class="od-notes-box">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                class="od-notes-icon">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                <line x1="16" y1="17" x2="8" y2="17"></line>
                                <polyline points="10 9 9 9 8 9"></polyline>
                            </svg>
                            <?php echo nl2br(htmlspecialchars($order['notes'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right Column: Sidebar -->
            <div class="od-sidebar-col">
                <!-- Customer Info -->
                <div class="od-card">
                    <div class="od-card-header">
                        <span class="od-card-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </span>
                        Customer Details
                    </div>
                    <div class="od-card-body">
                        <?php
                        $customerName = $order['display_name'] ?? $order['customer_name'] ?? null;
                        $customerPhone = $order['display_phone'] ?? $order['customer_phone'] ?? null;
                        $customerEmail = $order['display_email'] ?? $order['customer_email'] ?? null;
                        ?>

                        <?php if ($customerName): ?>
                            <div class="od-info-group">
                                <label>Name</label>
                                <div class="od-info-value"><?php echo htmlspecialchars($customerName); ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if ($customerPhone): ?>
                            <div class="od-info-group">
                                <label>Phone</label>
                                <div class="od-info-value-row">
                                    <span><?php echo htmlspecialchars($customerPhone); ?></span>
                                    <?php
                                    $phoneNum = preg_replace('/[^0-9]/', '', $customerPhone);
                                    $waLink = 'https://wa.me/' . $phoneNum;
                                    ?>
                                    <a href="<?php echo htmlspecialchars($waLink); ?>" target="_blank" class="od-action-btn wa"
                                        title="WhatsApp">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                                            <path
                                                d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z" />
                                        </svg>
                                    </a>
                                    <?php
                                    $mapLat = $order['location_lat'] ?? '';
                                    $mapLng = $order['location_lng'] ?? '';
                                    $mapQuery = trim($order['user_street_location'] ?? '');
                                    
                                    if (!empty($mapLat) && !empty($mapLng) || !empty($mapQuery)):
                                        $lat = $mapLat ?: '27.690290';
                                        $lng = $mapLng ?: '84.446950';
                                        $gmapLink = "https://www.google.com/maps/search/?api=1&query=" . urlencode("$lat,$lng");
                                    ?>
                                        <a href="<?php echo htmlspecialchars($gmapLink); ?>"
                                            target="_blank" class="od-action-btn maps" title="Track on Google Maps">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2">
                                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z" />
                                                <circle cx="12" cy="10" r="3" />
                                            </svg>
                                        </a>
                                    <?php endif; ?>
                                    <a href="tel:<?php echo htmlspecialchars($customerPhone); ?>" class="od-action-btn call"
                                        title="Call">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2">
                                            <path
                                                d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z">
                                            </path>
                                        </svg>
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($customerEmail): ?>
                            <div class="od-info-group">
                                <label>Email</label>
                                <div class="od-info-value-row">
                                    <a href="mailto:<?php echo htmlspecialchars($customerEmail); ?>"
                                        class="od-info-value od-info-link">
                                        <?php echo htmlspecialchars($customerEmail); ?>
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment Information (Moved) -->
                <div class="od-card highlight-card">
                    <div class="od-card-header">
                        <span class="od-card-icon">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <rect x="2" y="5" width="20" height="14" rx="2" />
                                <line x1="2" y1="10" x2="22" y2="10" />
                            </svg>
                        </span>
                        Payment Information
                    </div>
                    <div class="od-card-body">
                        <div class="payment-status-wrapper">
                            <div class="curr-payment-status <?php echo strtolower($order['payment_status'] ?? 'unpaid') === 'paid' ? 'paid' : 'unpaid'; ?>">
                                <?php
                                $payStatus = strtolower($order['payment_status'] ?? 'unpaid');
                                $payMethod = strtoupper($order['payment_method'] ?? '');
                                $cashAmt = floatval($order['paid_amount_cash'] ?? 0);
                                $onlineAmt = floatval($order['paid_amount_online'] ?? 0);
                                
                                if ($payStatus === 'paid') {
                                    if ($payMethod === 'COD') {
                                        if ($cashAmt > 0 && $onlineAmt == 0) {
                                            echo 'PAID BY CASH';
                                        } elseif ($onlineAmt > 0 && $cashAmt == 0) {
                                            echo 'PAID BY ONLINE (COD)';
                                        } else {
                                            // Fallback
                                            echo 'PAID (COD)';
                                        }
                                    } elseif ($payMethod === 'SPLIT') {
                                        echo 'SPLIT PAID: <br>';
                                        echo '<span style="font-size:0.9em; font-weight:normal;">Rs.' . number_format($cashAmt) . ' CASH + <br>';
                                        echo 'Rs.' . number_format($onlineAmt) . ' ONLINE (COD)</span>';
                                    } elseif (in_array($payMethod, ['ESEWA', 'ONLINE', 'KHALTI', 'CONNECTIPS']) || empty($payMethod)) {
                                        echo 'PAID VIA ESEWA';
                                    } else {
                                        echo 'PAID via ' . ucfirst(strtolower($payMethod));
                                    }
                                } elseif ($payStatus === 'failed' || $payStatus === 'cancelled') {
                                    echo '<span style="color:red; font-weight:600;">Payment Rejected</span>';
                                } elseif ($payStatus === 'pending' && in_array($payMethod, ['ESEWA', 'KHALTI', 'CONNECTIPS', 'ONLINE'])) {
                                    echo '<span style="color:#d97706; font-weight:600;">Payment Processing...</span>';
                                } else {
                                    echo 'UNPAID';
                                }
                                ?>
                            </div>
                        </div>

                        <?php
                        // Logic for Controls: Show if UNPAID or if existing payment is COD/SPLIT (editable)
                        // Hide if purely ONLINE/ESEWA automated payment
                        $isPaid = ($payStatus === 'paid');
                        $isAutomated = in_array($payMethod, ['ESEWA', 'KHALTI', 'CONNECTIPS', 'ONLINE']);
                        
                        // We disable ANY editing if status is PAID, regardless of method.
                        // AND only show controls if order status is 'received'
                        $currentOrderStatus = strtolower(trim($order['status'] ?? ''));
                        $showControls = !$isPaid && ($currentOrderStatus === 'received');
                        ?>

                        <?php if ($showControls): ?>
                            <button class="btn-update-payment"
                                onclick="document.getElementById('paymentOptions').classList.toggle('hidden')">
                                <?php echo $isPaid ? 'Edit Payment' : 'Update Payment'; ?>
                            </button>

                            <div id="paymentOptions" class="payment-options hidden">
                                <p class="pay-opt-title">Update Payment Status:</p>
                                <div class="premium-pay-buttons">
                                    <!-- Cash Button -->
                                    <button class="pay-btn premium-btn-cash" onclick="customConfirmPaymentUpdate(<?php echo $order['id']; ?>, 'cash')">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="2" y="6" width="20" height="12" rx="2" ry="2"></rect>
                                            <circle cx="12" cy="12" r="2"></circle>
                                            <path d="M6 12h.01M18 12h.01"></path>
                                        </svg>
                                        Cash Payment
                                    </button>
                                    
                                    <!-- Online (COD) Button -->
                                    <button class="pay-btn premium-btn-online" onclick="customConfirmPaymentUpdate(<?php echo $order['id']; ?>, 'cod_online')">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="2" y="5" width="20" height="14" rx="2" ry="2"></rect>
                                            <line x1="2" y1="10" x2="22" y2="10"></line>
                                        </svg>
                                        Online Transfer
                                    </button>
                                    
                                    <!-- Split Button Toggle -->
                                    <button class="pay-btn premium-btn-split" onclick="document.getElementById('split-form-<?php echo $order['id']; ?>').style.display='block'; this.closest('.premium-pay-buttons').style.display='none';">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                                        </svg>
                                        Split Payment
                                    </button>
                                </div>

                                <!-- SPLIT PAY FORM PREMIUM -->
                                <div id="split-form-<?php echo $order['id']; ?>" class="premium-split-form" style="display:none;">
                                    <div class="split-header">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                            <polyline points="14 2 14 8 20 8"></polyline>
                                            <line x1="12" y1="18" x2="12" y2="12"></line>
                                            <line x1="9" y1="15" x2="15" y2="15"></line>
                                        </svg>
                                        Set Split Amounts
                                    </div>
                                    <div class="split-inputs-row">
                                        <div class="split-input-group">
                                            <label>Cash Amount</label>
                                            <div class="input-with-icon">
                                                <span>Rs.</span>
                                                <input type="number" id="split-cash-<?php echo $order['id']; ?>" class="premium-form-control" placeholder="0.00" oninput="calculateSplit(<?php echo $order['id']; ?>, <?php echo $order['total']; ?>, 'cash')">
                                            </div>
                                        </div>
                                        <div class="split-input-group">
                                            <label>Online Amount</label>
                                            <div class="input-with-icon">
                                                <span>Rs.</span>
                                                <input type="number" id="split-online-<?php echo $order['id']; ?>" class="premium-form-control" placeholder="0.00" oninput="calculateSplit(<?php echo $order['id']; ?>, <?php echo $order['total']; ?>, 'online')">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="split-total-check" id="split-check-<?php echo $order['id']; ?>">
                                        Total: Rs. <?php echo number_format($order['total'], 2); ?>
                                    </div>
                                    <div class="split-actions">
                                        <button onclick="document.getElementById('split-form-<?php echo $order['id']; ?>').style.display='none'; document.querySelector('.premium-pay-buttons').style.display='grid';" class="pc-btn-cancel">Cancel</button>
                                        <button onclick="customSubmitSplitPay(<?php echo $order['id']; ?>, <?php echo $order['total']; ?>)" class="pc-btn-confirm split-confirm-btn">Confirm Split</button>
                                    </div>
                                </div>

                            </div>
                        <?php else: ?>
                            <div class="od-payment-readonly">
                                <span style="font-size:12px; color:#6b7280; display:block; margin-bottom:4px;">Payment Verified (Automated)</span>
                                <?php if (!empty($txn['vendor_ref_id'])): ?>
                                    <div style="margin-top:4px;">
                                        <span style="font-size:10px; color:#6b7280; text-transform:uppercase; font-weight:600;">TXN ID:</span>
                                        <span style="font-size:11px; color:#111827; font-family:monospace; background:#f3f4f6; padding:2px 6px; border-radius:4px; margin-left:4px;">
                                            <?php echo htmlspecialchars($txn['vendor_ref_id']); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <?php 
                                    // Try to find sender details in response_json
                                    if (!empty($txn['response_json'])) {
                                        $txnData = json_decode($txn['response_json'], true);
                                        // Check for common fields eSewa might send (though usually they don't send customer mobile)
                                        $senderMobile = $txnData['mobile'] ?? $txnData['sender_mobile'] ?? $txnData['customer_mobile'] ?? null;
                                        if ($senderMobile) {
                                            echo '<div style="margin-top:2px;">';
                                            echo '<span style="font-size:10px; color:#6b7280; text-transform:uppercase; font-weight:600;">eSewa ID:</span>';
                                            echo '<span style="font-size:11px; color:#111827; font-family:monospace; margin-left:4px;">' . htmlspecialchars($senderMobile) . '</span>';
                                            echo '</div>';
                                        }
                                    }
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Delivery/Pickup Info -->
                <div class="od-card">
                    <div class="od-card-header">
                        <span class="od-card-icon">
                            <?php if ($order['service_type'] === 'delivery'): ?>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2">
                                    <rect x="1" y="3" width="15" height="13"></rect>
                                    <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
                                    <circle cx="5.5" cy="18.5" r="2.5"></circle>
                                    <circle cx="18.5" cy="18.5" r="2.5"></circle>
                                </svg>
                            <?php else: ?>
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2">
                                    <polyline points="20 6 9 17 4 12"></polyline>
                                </svg>
                            <?php endif; ?>
                        </span>
                        <?php echo $order['service_type'] === 'delivery' ? 'Delivery Details' : 'Pickup Details'; ?>
                    </div>
                    <div class="od-card-body">
                        <?php if ($order['service_type'] === 'delivery'): ?>
                            <div class="od-info-group">
                                <label>Address</label>
                                <div class="od-info-value" style="font-size: 13px; line-height: 1.4;">
                                    <?php 
                                    if (!empty($order['delivery_address'])) {
                                        echo htmlspecialchars($order['delivery_address']);
                                    } else {
                                        $deliveryLoc = $order['user_delivery_location'] ?? '';
                                        $streetLoc = $order['user_street_location'] ?? '';
                                        
                                        if ($deliveryLoc && $streetLoc) {
                                            echo htmlspecialchars($deliveryLoc . ', ' . $streetLoc);
                                        } elseif ($deliveryLoc) {
                                            echo htmlspecialchars($deliveryLoc);
                                        } elseif ($streetLoc) {
                                            echo htmlspecialchars($streetLoc);
                                        } else {
                                            echo 'N/A';
                                        }
                                    }
                                    ?>
                                </div>
                                <?php if (!empty($order['location_lat']) && !empty($order['location_lng'])): ?>
                                    <div style="font-size: 11px; color: #64748b; font-weight: 600; margin-top: 6px; display: flex; align-items: center; gap: 4px;">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"></path>
                                            <circle cx="12" cy="10" r="3"></circle>
                                        </svg>
                                        <?php echo htmlspecialchars($order['location_lat'] . ', ' . $order['location_lng']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="od-info-group">
                                <label>Pickup Time</label>
                                <div class="od-info-value od-highlight">
                                    <?php echo htmlspecialchars($order['pickup_time'] ?? 'N/A'); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <style>
        .highlight-card .od-card-header {
            background: #f0f9ff;
            color: #0369a1;
            border-bottom: 1px solid #e0f2fe;
        }

        .highlight-card .od-card-icon {
            color: #0ea5e9;
        }

        .payment-status-wrapper {
            margin-bottom: 16px;
            text-align: center;
        }

        .curr-payment-status {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .curr-payment-status.unpaid {
            background: #fee2e2;
            color: #ef4444;
            border: 1px solid #fecaca;
        }

        .curr-payment-status.paid {
            background: #dcfce7;
            color: #16a34a;
            border: 1px solid #bbf7d0;
        }

        .btn-update-payment {
            width: 100%;
            padding: 10px;
            border-radius: 8px;
            border: none;
            background: #2563eb;
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-update-payment:hover {
            background: #1d4ed8;
        }

        .payment-options {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px dashed #cbd5e1;
        }

        .payment-options.hidden {
            display: none;
        }

        .pay-opt-title {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .premium-pay-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 10px;
        }

        .pay-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 16px;
            border-radius: 12px;
            border: 2px solid transparent;
            background: white;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
        }

        .premium-btn-cash {
            color: #047857;
            background: #ecfdf5;
            border-color: #a7f3d0;
        }

        .premium-btn-cash:hover {
            background: #10b981;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.3);
        }

        .premium-btn-online {
            color: #1d4ed8;
            background: #eff6ff;
            border-color: #bfdbfe;
        }

        .premium-btn-online:hover {
            background: #3b82f6;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(59, 130, 246, 0.3);
        }

        .premium-btn-split {
            grid-column: span 2;
            color: #6d28d9;
            background: #f5f3ff;
            border-color: #ddd6fe;
            flex-direction: row;
        }

        .premium-btn-split:hover {
            background: #8b5cf6;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(139, 92, 246, 0.3);
        }
        
        /* Split form styling */
        .premium-split-form {
            margin-top: 15px;
            background: #ffffff;
            padding: 20px;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
        }
        
        .split-header {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 16px;
            font-size: 15px;
        }
        
        .split-header svg { color: #8b5cf6; }

        .split-inputs-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }

        .split-input-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 6px;
        }

        .input-with-icon {
            display: flex;
            align-items: center;
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            padding: 0 12px;
            transition: border-color 0.2s;
        }
        
        .input-with-icon:focus-within { border-color: #8b5cf6; }

        .input-with-icon span {
            color: #64748b;
            font-weight: 600;
            font-size: 14px;
        }

        .premium-form-control {
            width: 100%;
            padding: 12px 8px;
            border: none;
            background: transparent;
            font-weight: 700;
            color: #0f172a;
            font-size: 14px;
            outline: none;
        }
        
        .premium-form-control::-webkit-inner-spin-button { display: none; }
        
        .split-total-check {
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            color: #64748b;
            background: #f1f5f9;
            padding: 8px;
            border-radius: 6px;
            margin-bottom: 16px;
            border: 1px solid #e2e8f0;
        }

        .split-actions {
            display: flex;
            gap: 12px;
        }

        .split-actions button { flex: 1; padding: 12px; }

        .split-actions .split-confirm-btn {
            background: #60bb46;
            color: #ffffff;
            box-shadow: 0 4px 6px -1px rgba(96, 187, 70, 0.2);
        }

        .split-actions .split-confirm-btn:hover {
            background: #4ca336;
            box-shadow: 0 10px 15px -3px rgba(96, 187, 70, 0.3);
            transform: translateY(-2px);
        }
    </style>


    <?php
    $html = ob_get_clean();

    echo json_encode(['success' => true, 'html' => $html]);

} catch (PDOException $e) {
    error_log("Get order details error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>
