<?php
header('Content-Type: application/json');
require_once __DIR__ . '/_rider_only.php';
require_once __DIR__ . '/../../../config/db.php';

$orderId = intval($_GET['order_id'] ?? 0);

if (!$orderId) {
    echo json_encode(['success' => false, 'error' => 'Invalid order ID']);
    exit;
}

try {
    // Get order with user information
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
               COALESCE(o.customer_email, u.email) as display_email
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }

    // Fetch rider name for display
    $riderName = '';
    if (!empty($order['rider_id'])) {
        try {
            $riderStmt = $pdo->prepare("SELECT * FROM riders WHERE id = ?");
            $riderStmt->execute([$order['rider_id']]);
            $riderRow = $riderStmt->fetch(PDO::FETCH_ASSOC);
            if ($riderRow) {
                $riderName = $riderRow['full_name'] ?? ($riderRow['name'] ?? ($riderRow['username'] ?? ''));
            }
        } catch (Exception $re) {
            // ignore
        }
    }

    // Get order items
    $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll();

    // Get payment transaction details
    $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE order_id = ? AND status = 'COMPLETE' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$orderId]);
    $txn = $stmt->fetch();

    // Generate HTML
    ob_start();
    ?>
    <style>
        .order-detail-premium {
            font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            color: #1e293b;
        }

        .od-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 24px;
            gap: 16px;
        }

        .od-id {
            font-size: 1.25rem;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
            letter-spacing: -0.02em;
        }

        .od-meta {
            margin-top: 4px;
            font-size: 0.8rem;
            color: #64748b;
            font-weight: 500;
            display: flex;
            gap: 8px;
        }

        .od-status {
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            display: flex;
            align-items: center;
            gap: 6px;
            background: #f1f5f9;
            color: #475569;
        }

        .od-status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        .status-confirmed {
            background: #eef2ff;
            color: #4f46e5;
        }

        .status-confirmed .od-status-dot {
            background: #4f46e5;
        }

        .status-ready {
            background: #fff7ed;
            color: #ea580c;
        }

        .status-ready .od-status-dot {
            background: #ea580c;
        }

        .status-received {
            background: #f0fdf4;
            color: #16a34a;
        }

        .status-received .od-status-dot {
            background: #16a34a;
        }

        .service-tag {
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 700;
            background: #f1f5f9;
            color: #64748b;
        }

        .od-content-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        @media (min-width: 640px) {
            .od-content-grid {
                grid-template-columns: 1.5fr 1fr;
            }
        }

        .od-section {
            background: white;
            border-radius: 16px;
            border: 1px solid #f1f5f9;
            padding: 16px;
            margin-bottom: 20px;
        }

        .od-section-title {
            font-size: 0.85rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 16px;
            display: block;
        }

        .od-item-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 12px 0;
            border-bottom: 1px solid #f8fafc;
            gap: 16px;
        }

        .od-item-row:last-child {
            border-bottom: none;
        }

        .od-item-info {
            flex: 1;
            min-width: 0;
        }

        .od-item-name {
            font-weight: 700;
            font-size: 0.95rem;
            color: #1e293b;
            word-wrap: break-word;
            line-height: 1.4;
        }

        .od-item-qty {
            font-size: 0.8rem;
            color: #64748b;
            font-weight: 600;
            margin-top: 2px;
        }

        .od-item-price {
            font-weight: 800;
            color: #0f172a;
            font-size: 0.95rem;
            white-space: nowrap;
        }

        .od-totals-section {
            background: #f8fafc;
            border-radius: 16px;
            padding: 20px;
            border: 1px solid #f1f5f9;
        }

        .od-total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .od-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #f1f5f9;
            padding: 16px;
            margin-bottom: 16px;
        }

        .od-label {
            font-size: 0.75rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 6px;
            display: block;
        }

        .od-value {
            font-size: 0.95rem;
            font-weight: 700;
            color: #1e293b;
        }

        /* Payment Logic */
        .payment-options-title {
            font-size: 11px;
            font-weight: 700;
            color: #94a3b8;
            margin-bottom: 12px;
            display: block;
            text-align: center;
        }

        .od-payment-grid-choice {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }

        .od-payment-choice-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            padding: 16px 10px;
            border-radius: 14px;
            border: 1.5px solid #f1f5f9;
            background: white;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }

        .od-payment-choice-btn:hover {
            border-color: #6366f1;
            background: #f8fafc;
        }

        .od-payment-choice-btn.btn-choice-cash {
            color: #16a34a;
        }

        .od-payment-choice-btn.btn-choice-online {
            color: #2563eb;
        }

        .od-payment-choice-btn.btn-choice-split {
            grid-column: span 2;
            flex-direction: row;
            justify-content: center;
            gap: 10px;
        }

        .split-container {
            padding: 20px;
            background: #fdf2f2; /* Subtle warmth */
            border-radius: 18px;
            border: 1.5px dashed #fecaca;
            margin-top: 15px;
            display: none;
            animation: slideDownIn 0.3s ease-out;
        }

        @keyframes slideDownIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .split-container.active {
            display: block;
        }

        .split-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
            background: white;
            transition: all 0.2s;
            margin-bottom: 10px;
            outline: none;
        }

        .split-input:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }

        .split-input[readonly] {
            background: #f8fafc;
            color: #64748b;
            cursor: not-allowed;
        }

        .btn-update-payment {
            width: 100%;
            padding: 14px;
            background: #6366f1;
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 800;
            font-size: 0.9rem;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.2);
            transition: 0.2s;
            text-transform: uppercase;
        }

        .od-payment-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
        }

        .od-payment-badge.paid {
            background: #dcfce7;
            color: #166534;
        }

        .od-payment-badge.unpaid {
            background: #fee2e2;
            color: #991b1b;
        }
    </style>
    <div class="order-detail-premium">
        <div class="od-header">
            <div class="od-header-left">
                <h1 class="od-id">#ORD-<?php echo str_pad($order['id'], 5, '0', STR_PAD_LEFT); ?></h1>
                <div class="od-meta">
                    <span><?php echo date('M d, Y', strtotime($order['created_at'])); ?></span>
                    <span>•</span>
                    <span><?php echo date('h:i A', strtotime($order['created_at'])); ?></span>
                </div>
            </div>
            <div class="od-header-right">
                <?php
                $status = strtolower(trim($order['status']));
                $statusClass = $status;
                if ($status === 'confirmed' || $status === 'preparing')
                    $statusClass = 'confirmed';
                ?>
                <div class="od-status status-<?php echo $statusClass; ?>">
                    <div class="od-status-dot"></div>
                    <?php echo ucfirst($status); ?>
                </div>
            </div>
        </div>

        <?php
        $canPay = intval($_GET['can_pay'] ?? 0);
        ?>
        <div class="od-content-grid">
            <div class="od-main-col">
                <div class="od-section">
                    <span class="od-section-title">Items Summary</span>
                    <div class="od-items-list">
                        <?php foreach ($items as $item): ?>
                            <div class="od-item-row">
                                <div class="od-item-info">
                                    <div class="od-item-name"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                    <div class="od-item-qty">x <?php echo $item['quantity']; ?></div>
                                </div>
                                <div class="od-item-price">Rs. <?php echo number_format($item['line_total']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="od-totals-section">
                    <div class="od-total-row" style="font-size:14px; font-weight:600; color:#64748b;">
                        <span>Subtotal</span>
                        <span>Rs. <?php echo number_format($order['total'] - $order['delivery_fee']); ?></span>
                    </div>
                    <div class="od-total-row" style="font-size:14px; font-weight:600; color:#4f46e5;">
                        <span>Distance</span>
                        <span><?php echo !empty($order['delivery_distance_km']) ? number_format($order['delivery_distance_km'], 2) . ' KM' : 'N/A'; ?></span>
                    </div>
                    <div class="od-total-row" style="font-size:14px; font-weight:600; color:#64748b; padding-bottom:12px; border-bottom:1px solid #e2e8f0; margin-bottom:12px;">
                        <span>Delivery Fee</span>
                        <span>Rs. <?php echo number_format($order['delivery_fee']); ?></span>
                    </div>
                    <?php if (($order['rider_tip'] ?? 0) > 0): ?>
                        <div class="od-total-row" style="font-size:14px; font-weight:700; color:#10b981; margin-bottom:12px;">
                            <span>Rider Tip</span>
                            <span>Rs. <?php echo number_format($order['rider_tip']); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="od-total-row">
                        <span style="color:#1e293b; font-weight:900;">Grand Total</span>
                        <span style="font-size:1.25rem; font-weight:900; color:#4f46e5;">Rs. <?php echo number_format($order['total']); ?></span>
                    </div>
                </div>
            </div>

            <div class="od-sidebar-col">
                <div class="od-card">
                    <span class="od-label">Customer</span>
                    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                        <div>
                            <div class="od-value" style="font-size:1.1rem;"><?php echo htmlspecialchars($order['display_name']); ?></div>
                            <div class="od-value" style="color:#64748b; font-size:0.9rem; margin-top:4px;"><?php echo htmlspecialchars($order['display_phone']); ?></div>
                        </div>
                        <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $order['display_phone']); ?>" target="_blank" style="background:#22c55e; color:white; width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; text-decoration:none;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z" /></svg>
                        </a>
                    </div>
                </div>

                <div class="od-card">
                    <span class="od-label">Payment Information</span>
                    <div style="text-align:center; margin-bottom:12px;">
                        <?php
                        $payStatus = strtolower($order['payment_status'] ?? 'unpaid');
                        $payClass = ($payStatus === 'paid') ? 'paid' : 'unpaid';
                        ?>
                        <span class="od-payment-badge <?php echo $payClass; ?>">
                            <?php echo strtoupper($payStatus); ?>
                        </span>
                    </div>

                    <?php if ($payStatus === 'paid'): 
                        $payMethod = strtoupper($order['payment_method'] ?: 'ESEWA');
                        ?>
                        <div style="text-align:center; padding:12px; background:#f0fdf4; border-radius:12px; border:1px solid #dcfce7; margin-bottom: 12px;">
                            <div style="font-size:0.9rem; color:#15803d; font-weight:700;">
                                <?php
                                if (($payMethod === 'COD' || $payMethod === 'CASH') && ($order['paid_amount_cash'] ?? 0) > 0) {
                                    echo "Paid by Cash";
                                } elseif ($payMethod === 'COD' && ($order['paid_amount_online'] ?? 0) > 0) {
                                    echo "Online via COD";
                                } elseif ($payMethod === 'COD') {
                                    echo "Collected by Rider (COD)";
                                } elseif ($payMethod === 'SPLIT') {
                                    echo "Split Payment Received";
                                } else {
                                    echo "Paid via " . $payMethod;
                                }
                                ?>
                            </div>
                        </div>

                        <?php if ($order['rider_id'] == $riderId && $status === 'received'): ?>
                            <button class="btn-update-payment" style="background: linear-gradient(135deg, #10b981, #059669); margin-top:5px; margin-bottom: 15px;" onclick="handlePaymentUpdate(<?php echo $order['id']; ?>, 'prepaid')">
                                <div style="display:flex; align-items:center; gap:8px; justify-content:center;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                        <polyline points="22 4 12 14.01 9 11.01"></polyline>
                                    </svg>
                                    FINALIZE & COMPLETE
                                </div>
                            </button>
                        <?php endif; ?>

                    <?php elseif ($order['rider_id'] == $riderId && $canPay && $status === 'received'): ?>
                        <div id="paymentOptionsContainer">
                            <div class="od-payment-grid-choice">
                                <button class="od-payment-choice-btn btn-choice-cash" onclick="handlePaymentUpdate(<?php echo $order['id']; ?>, 'cash')">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                                    <span style="font-size:11px; font-weight:700;">CASH</span>
                                </button>
                                <button class="od-payment-choice-btn btn-choice-online" onclick="handlePaymentUpdate(<?php echo $order['id']; ?>, 'cod_online')">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="5" width="20" height="14" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>
                                    <span style="font-size:11px; font-weight:700;">ONLINE</span>
                                </button>
                                <button class="od-payment-choice-btn btn-choice-split" onclick="toggleSplitContainer()">
                                    <span style="font-size:11px; font-weight:700;">SPLIT PAYMENT</span>
                                </button>
                            </div>

                            <div id="splitContainer" class="split-container">
                                <input type="number" id="splitCash" class="split-input" placeholder="Cash Amount" oninput="calculateSplit(<?php echo $order['total']; ?>)" style="margin-bottom:8px;">
                                <input type="number" id="splitOnline" class="split-input" placeholder="Online Amount" readonly>
                                <button class="btn-update-payment" style="margin-top:10px; padding:10px;" onclick="handlePaymentUpdate(<?php echo $order['id']; ?>, 'split')">Confirm</button>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="text-align:center; color:#64748b; font-size:0.8rem; font-weight:600; padding:10px; border:1px dashed #e2e8f0; border-radius:12px;">
                            Payment status will be updated upon delivery
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="od-card">
            <span class="od-label">Delivery Address</span>
            <div style="font-size:0.95rem; font-weight:600; color:#475569; line-height:1.5;">
                <?php
                $locName = $order['delivery_address'] ?: ($order['user_delivery_location'] ?: ($order['user_street_location'] ?: 'N/A'));
                if (!empty($order['location_lat']) && !empty($order['location_lng'])) {
                    echo '<div>' . htmlspecialchars($locName) . '</div>';
                    echo '<div style="font-size: 0.8rem; color: #94a3b8; font-weight: 500; margin-top: 4px;">GPS: ' . htmlspecialchars($order['location_lat'] . ', ' . $order['location_lng']) . '</div>';
                } else {
                    echo htmlspecialchars($locName);
                }
                ?>
            </div>
            <?php if (!empty($order['notes'])): ?>
                <div style="margin-top:12px; padding-top:12px; border-top:1px dashed #f1f5f9;">
                    <span class="od-label" style="font-size:0.65rem;">Notes / Instructions</span>
                    <div style="font-size:0.9rem; font-weight:500; color:#64748b;">
                        <?php echo nl2br(htmlspecialchars($order['notes'])); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($riderName)): ?>
            <div class="od-card" style="background:#f0fdf4; border-color:#dcfce7; margin-bottom:0;">
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="background:#16a34a; color:white; width:40px; height:40px; border-radius:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0; box-shadow:0 4px 10px rgba(22, 163, 74, 0.2);">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <rect x="1" y="3" width="15" height="13"></rect>
                            <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
                            <circle cx="5.5" cy="18.5" r="2.5"></circle>
                            <circle cx="18.5" cy="18.5" r="2.5"></circle>
                        </svg>
                    </div>
                    <div style="flex:1;">
                        <span class="od-label" style="color:#16a34a;">Delivery Rider</span>
                        <div class="od-value" style="font-size:1.05rem;"><?php echo htmlspecialchars($riderName); ?></div>
                    </div>
                    <div style="text-align:right;">
                        <?php
                        $riderOrderStatus = strtolower(trim($order['status']));
                        if ($riderOrderStatus === 'received') echo '<span class="od-status status-received">ORDER RECEIVED</span>';
                        elseif ($riderOrderStatus === 'delivery') echo '<span class="od-status" style="background:#e0f2fe; color:#0369a1;">IN TRANSIT</span>';
                        else echo '<span class="od-status" style="background:#f1f5f9; color:#475569;">ASSIGNED</span>';
                        ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php
    $html = ob_get_clean();
    echo json_encode(['success' => true, 'html' => $html]);

} catch (Exception $e) {
    error_log("Rider Get order details error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>
