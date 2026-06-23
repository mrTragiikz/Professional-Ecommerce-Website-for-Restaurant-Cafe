<?php
/**
 * API: Get Kitchen Receipt (KOT) HTML for Printing
 * Professional Minimalist Layout for Kitchen Use
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
               COALESCE(o.customer_name, u.name) as display_name,
               COALESCE(o.customer_phone, u.phone) as display_phone
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        $whereSql
    ");
    $stmt->execute($whereParams);
    $order = $stmt->fetch();

    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll();

    function getNepaliBSDate($dateStr) {
        $timestamp = strtotime($dateStr);
        $engYear = (int)date('Y', $timestamp);
        $engMonth = (int)date('m', $timestamp);
        $engDay = (int)date('d', $timestamp);
        $nepYear = $engYear + 56;
        $nepMonth = $engMonth + 8;
        $nepDay = $engDay + 16;
        if ($nepDay > 30) { $nepDay -= 30; $nepMonth++; }
        if ($nepMonth > 12) { $nepMonth -= 12; $nepYear++; }
        $months = ["", "Baisakh", "Jestha", "Ashadh", "Shrawan", "Bhadra", "Ashwin", "Kartik", "Mangsir", "Poush", "Magh", "Falgun", "Chaitra"];
        return $nepDay . " " . $months[$nepMonth] . ", " . $nepYear;
    }

    $nepaliDate = getNepaliBSDate($order['created_at']);

    ob_start();
    ?>
    <div id="printableKitchenReceipt" class="receipt-print-wrapper"
        style="width: 320px; padding: 8px 10px; background: #fff; font-family: 'Inter', system-ui, sans-serif; color: #000; line-height: 1.15; box-sizing: border-box;">

        <style>
            @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');
            .receipt-print-wrapper * { 
                box-sizing: border-box; 
                font-family: 'Inter', sans-serif; 
                color: #000 !important; 
                font-weight: 700 !important; /* ensure all text prints bold & dark */
            }
            .rc-line      { border-bottom: 1px solid #000; margin: 2px 0; }
            .rc-line-bold { border-bottom: 2px solid #000; margin: 3px 0; }
            .rc-dots      { border-bottom: 1px dotted #000; margin: 2px 0; }
        </style>

        <div style="text-align: center; margin-bottom: 4px; font-size: 9px;">
            <div style="font-size: 14px; font-weight: 900; letter-spacing: 0.08em; text-transform: uppercase;">JUSTKLEEK</div>
            <div style="font-size: 7px; font-weight: 700; letter-spacing: 0.18em; color: #000; margin-top: 1px; text-transform: uppercase;">Kitchen Order Ticket</div>
        </div>

        <div class="rc-line-bold"></div>
        <div style="text-align:center; font-weight: 800; font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase; margin: 2px 0;">
            KOT (KITCHEN)
        </div>
        <div class="rc-line-bold"></div>
        
        <div style="margin-top: 3px; font-size: 10px;">
            <div style="display:flex; justify-content:space-between;">
                <span>ORDER ID</span>
                <span style="font-weight:700; font-size: 11px;">#<?php echo str_pad($order['id'], 5, '0', STR_PAD_LEFT); ?></span>
            </div>
            <div class="rc-dots"></div>
            <div style="display:flex; justify-content:space-between; margin-top:2px;">
                <span>SERVICE</span>
                <span style="font-weight:700; text-transform:uppercase; font-size: 11px;"><?php echo $order['service_type']; ?></span>
            </div>
            <div class="rc-dots"></div>
            <div style="display:flex; justify-content:space-between; margin-top:2px;">
                <span>DATE/TIME</span>
                <span style="font-weight:700; font-size: 10px;"><?php echo $nepaliDate; ?> (<?php echo date('M d, Y', strtotime($order['created_at'])); ?>) | <?php echo date('h:i:s A', strtotime($order['created_at'])); ?></span>
            </div>
        </div>

        <div class="rc-line-bold" style="margin-top:8px;"></div>

        <!-- Customer Info -->
        <div style="margin-top: 3px; font-size: 9px;">
            <div style="font-size: 9px; font-weight: 700; color: #000; text-transform: uppercase;">Customer</div>
            <div style="font-size: 11px; font-weight: 800; margin-top: 2px; text-transform: uppercase;">
                <?php echo htmlspecialchars($order['display_name'] ?? 'Walk-in'); ?>
                <?php if (!empty($order['display_phone'])): ?>
                    <span style="font-size: 10px; font-weight: 600;"> (<?php echo htmlspecialchars($order['display_phone']); ?>)</span>
                <?php endif; ?>
            </div>
            <?php if ($order['service_type'] === 'delivery'): ?>
                <div style="font-size: 9px; font-weight: 600; color: #000; margin-top: 2px; line-height: 1.3;">
                    <?php 
                        $addr = !empty($order['delivery_address']) ? $order['delivery_address'] : ($order['user_delivery_location'] ?? '');
                        echo strtoupper(htmlspecialchars($addr)); 
                    ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="rc-line-bold" style="margin-top:8px;"></div>

        <table style="width: 100%; border-collapse: collapse; font-size: 9px; table-layout: fixed;">
            <thead>
                <tr>
                    <th style="text-align:left; padding:2px 0; font-weight:700; text-transform:uppercase; width:80%;">Item Name</th>
                    <th style="text-align:center; padding:2px 0; width:20%; font-weight:700; text-transform:uppercase;">Qty</th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="2"><div class="rc-line"></div></td></tr>
                <?php 
                $totalQty = 0;
                foreach ($items as $item): 
                    $totalQty += $item['quantity'];
                ?>
                    <tr>
                        <td style="padding:4px 0; padding-right: 5px;">
                            <div style="font-weight:700; font-size:11px; text-transform:uppercase; word-wrap:break-word;"><?php echo htmlspecialchars($item['item_name']); ?></div>
                        </td>
                        <td style="text-align:center; vertical-align:middle; padding:4px 0; font-weight:800; font-size:12px;">x<?php echo $item['quantity']; ?></td>
                    </tr>
                    <tr><td colspan="2"><div class="rc-dots"></div></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if (!empty($order['notes'])): ?>
            <div style="margin-top: 10px; padding: 6px; border: 1.8px solid #000; border-radius: 4px; background: #fff;">
                <div style="font-size: 9px; font-weight: 900; text-transform: uppercase; margin-bottom: 3px; border-bottom: 1px solid #000; padding-bottom: 2px;">Kitchen Notes:</div>
                <div style="font-size: 12px; font-weight: 800; line-height: 1.3; word-wrap: break-word;"><?php echo htmlspecialchars($order['notes']); ?></div>
            </div>
        <?php endif; ?>

        <div style="margin-top: 6px; padding: 6px; border: 1.5px solid #000; border-radius: 4px; display:flex; justify-content:space-between; align-items: center; font-weight: 800; text-transform: uppercase;">
            <span style="font-size: 11px;">Total Items</span>
            <span style="font-size: 16px;"><?php echo $totalQty; ?></span>
        </div>
        
        <div style="position: relative; text-align: center; margin-top: 20px; padding-bottom: 5px;">
            <div style="font-size: 10px; font-weight: 800;">- END OF KOT -</div>
            <div style="font-size: 11px; font-weight: 900; margin-top: 15px; border-top: 1px dotted #000; padding-top: 8px;">
                अलि छिटो छिटो खाना बनाऔं है
            </div>
            <div style="margin-top: 10px; font-size: 8px; font-weight: 700; color: #555; text-transform: uppercase; letter-spacing: 0.05em;">
                Software by Prabin Sharma
            </div>
        </div>
    </div>
    <?php
    $html = ob_get_clean();
    echo json_encode(['success' => true, 'html' => $html]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
