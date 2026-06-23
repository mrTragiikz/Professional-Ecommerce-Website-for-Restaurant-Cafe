<?php
/**
 * Total Sales Report - New Design
 */

require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
requireAdminPin();

require_once __DIR__ . '/../config/db.php';

// Filter Logic
$cutoffTime = '04:00:00';
$nowTime = date('H:i:s');

// If no date is provided, determine the default "business date"
if (!isset($_GET['date'])) {
    if ($nowTime < $cutoffTime) {
        $selectedDate = date('Y-m-d', strtotime('yesterday'));
    } else {
        $selectedDate = date('Y-m-d');
    }
} else {
    $selectedDate = $_GET['date'];
}

$pageTitle = 'Statement Of Operation (' . date('Y/m/d', strtotime($selectedDate)) . ')';


// Business Cycle: From 04:00:00 of $selectedDate to 03:59:59 of the next day
$startDate = $selectedDate . ' ' . $cutoffTime;
$nextDay = date('Y-m-d', strtotime('+1 day', strtotime($selectedDate)));
$endDate = $nextDay . ' 03:59:59';
$yesterday = date('Y-m-d', strtotime('-1 day', strtotime($selectedDate)));

// Calculations
global $pdo;

$riderNameCol = 'id';
$ordersHasDeliveredAt = false;
$ordersHasPaidAt = false;

// Schema Checks
if (isset($_SESSION['sales_schema_cache'])) {
    $riderNameCol = $_SESSION['sales_schema_cache']['riderNameCol'];
    $ordersHasDeliveredAt = $_SESSION['sales_schema_cache']['ordersHasDeliveredAt'];
    $ordersHasPaidAt = $_SESSION['sales_schema_cache']['ordersHasPaidAt'];
} else {
    try {
        // Check riders schema
        $stmt = $pdo->query("SHOW COLUMNS FROM riders");
        if ($stmt) {
            $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('full_name', $cols)) $riderNameCol = 'full_name';
            elseif (in_array('name', $cols)) $riderNameCol = 'name';
            elseif (in_array('username', $cols)) $riderNameCol = 'username';
        }
        // Check orders schema
        $stmt = $pdo->query("SHOW COLUMNS FROM orders");
        if ($stmt) {
            $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (in_array('delivered_at', $cols)) $ordersHasDeliveredAt = true;
            if (in_array('paid_at', $cols)) $ordersHasPaidAt = true;
        }
        $_SESSION['sales_schema_cache'] = [
            'riderNameCol' => $riderNameCol,
            'ordersHasDeliveredAt' => $ordersHasDeliveredAt,
            'ordersHasPaidAt' => $ordersHasPaidAt
        ];
    } catch (Exception $e) { }
}

$deliveredAtSql = $ordersHasDeliveredAt ? "o.delivered_at" : "o.updated_at";
$paidAtSql = $ordersHasPaidAt ? "o.paid_at" : "o.updated_at";

// This is the source of truth for "WHEN" an order counts toward the audit.
$timestampOfRecord = "COALESCE($paidAtSql, $deliveredAtSql, o.updated_at, o.created_at)";

// Branch Filtering
$selectedBranchId = getAdminBranchId();
if (isSuperAdmin() && isset($_GET['branch']) && $_GET['branch'] !== 'all') {
    $branchFromUrl = (int)$_GET['branch'];
    if ($branchFromUrl > 0) {
        $selectedBranchId = $branchFromUrl;
    }
}

$branchFilterSql = "";
if ($selectedBranchId) {
    $branchFilterSql = " AND o.restaurant_id = " . (int)$selectedBranchId;
}
// ------------------------

// Fetch transactions on demand
if (isset($_GET['ajax_transactions'])) {
    // Time filter now uses created_at range only, so MySQL can use idx_created_at / idx_sales_report
    $queryItems = "
        SELECT 
            o.id, o.created_at, o.updated_at, o.payment_method, o.payment_status,
            o.total, o.paid_amount_cash, o.paid_amount_online, o.delivery_fee,
            o.customer_name, u.name as user_name,
            COALESCE(o.customer_name, u.name) as display_name,
            CASE 
                WHEN o.delivery_fee > 0 THEN 'Delivery'
                ELSE 'Pickup'
            END as order_type,
            o.created_at as payment_time,
            r.id as rider_id,
            r.$riderNameCol as rider_display_name
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN riders r ON o.rider_id = r.id
        WHERE 
            o.created_at BETWEEN ? AND ?
            AND o.status != 'cancelled'
            AND o.payment_status = 'PAID'
            $branchFilterSql
        ORDER BY payment_time DESC
    ";
    
    $stmt = $pdo->prepare($queryItems);
    $stmt->execute([$startDate, $endDate]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($transactions)) {
        echo '<tr><td colspan="5" style="padding: 30px; text-align: center; color: #94a3b8;">No transactions found for this date.</td></tr>';
        exit;
    }

    foreach ($transactions as $txn) {
        // Build the HTML for this row
        $timeStr = date('h:i A', strtotime($txn['payment_time']));
        $orderType = htmlspecialchars($txn['order_type']);
        $orderId = str_pad($txn['id'], 5, '0', STR_PAD_LEFT);
        $totalFormatted = number_format($txn['total']);
        
        $pmRaw = trim($txn['payment_method'] ?? '');
        $status = strtolower(trim($txn['payment_status'] ?? 'unpaid'));
        if (empty($pmRaw) && $status === 'paid') $pmRaw = 'esewa';
        $pmUpp = strtoupper($pmRaw ?: 'CASH');
        
        $c = floatval($txn['paid_amount_cash'] ?? 0);
        $o = floatval($txn['paid_amount_online'] ?? 0);

        $methodHtml = '';
        if ($pmUpp === 'COD') {
            if ($c > 0 && $o == 0) $methodHtml = '<span style="background:#dcfce7; color:#16a34a; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:700;">Paid By Cash (COD)</span>';
            elseif ($o > 0 && $c == 0) $methodHtml = '<span style="background:#eff6ff; color:#2563eb; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:700;">Paid By Online (COD)</span>';
            else $methodHtml = '<span style="padding:3px 8px; border-radius:4px; font-size:11px; font-weight:700; color:#4b5563; background:#e5e7eb;">Paid (COD)</span>';
        } elseif ($pmUpp === 'SPLIT') {
            $methodHtml = '<div style="background:#fff7ed; border:1px solid #fdba74; color:#c2410c; padding:6px 8px; border-radius:6px; font-size:11px; font-weight:600; text-align:left; width: 100%; box-sizing: border-box; margin-bottom:4px;"><div style="font-weight:800; border-bottom:1px solid #fdba74; padding-bottom:2px; margin-bottom:3px; font-size:10px; text-transform:uppercase; letter-spacing:0.5px;">Split Payment</div><div style="display:flex; justify-content:space-between; margin-bottom:1px;"><span>Cash:</span> <span>Rs.' . number_format($c) . '</span></div><div style="display:flex; justify-content:space-between;"><span>Online:</span> <span>Rs.' . number_format($o) . '</span></div></div>';
        } elseif ($pmUpp === 'ESEWA' || $pmUpp === 'ONLINE') {
            $methodHtml = '<div style="display:flex; align-items:center; gap:4px;"><img src="../assets/esewalogo.jpg" alt="eSewa" style="height:20px; width:auto; border-radius:3px;"><span style="font-size:11px; font-weight:800; color:#000;">Esewa</span><span style="font-size:11px; font-weight:700; color:#059669;">Paid</span></div>';
        } else {
            $methodHtml = '<span style="padding:3px 8px; border-radius:4px; font-size:11px; font-weight:700; background:#f3f4f6; color:#4b5563;">' . ucfirst(strtolower($pmUpp)) . '</span>';
        }

        $custHtml = '';
        if (!empty($txn['display_name'])) {
            $custHtml = '<span style="font-size: 11px; color: #1e293b; font-weight: 700; margin-top: 4px; display: block;">Customer: ' . htmlspecialchars($txn['display_name']) . '</span>';
        }

        $riderHtml = '';
        if (!empty($txn['rider_display_name'])) {
            $riderHtml = '<div style="margin-top: 6px; padding: 4px 8px; background: #fdf2f8; border: 1px solid #fbcfe8; border-radius: 6px; display: inline-flex; align-items: center; gap: 4px;"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#be185d" stroke-width="2.5"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><polyline points="17 11 19 13 23 9"></polyline></svg><span style="font-size: 10px; font-weight: 800; color: #be185d;">RIDER: ' . htmlspecialchars($txn['rider_display_name']) . '</span></div>';
        }

        echo '<tr class="transaction-row" style="border-bottom: 1px solid #cbd5e1;">';
        echo '<td style="padding: 15px 25px; color: #1e293b; font-weight: 600; font-size: 14px;">' . $timeStr . '</td>';
        echo '<td style="padding: 15px 25px;"><span style="display: inline-block; background: #f1f5f9; color: #475569; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; text-transform: uppercase; margin-right: 8px;">' . $orderType . '</span><span style="font-size: 13px; color: #64748b;">#' . $orderId . '</span></td>';
        echo '<td style="padding: 10px 15px;"><div style="display: inline-flex; flex-direction: column; align-items: center; gap: 2px;">' . $methodHtml . $custHtml . $riderHtml . '</div></td>';
        echo '<td style="padding: 15px 25px; text-align: right; color: #1e293b; font-weight: 700; font-size: 15px;">Rs. ' . $totalFormatted . '</td>';
        echo '<td style="padding: 15px 25px; text-align: center;" class="receipt-btn-col"><button onclick="openReceipt(' . $txn['id'] . ')" style="background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; padding: 6px 12px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; transition: all 0.2s;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7"></path><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><path d="M6 14h12v8H6z"></path></svg>Print</button></td>';
        echo '</tr>';
    }
    exit;
}

// Aggregate calculations
// Use created_at range so compound indexes on created_at / payment_status can be used.
$statsQueryItems = "
    SELECT 
        SUM(o.total) as sum_total,
        SUM(o.paid_amount_cash) as sum_cash,
        SUM(o.paid_amount_online) as sum_online,
        o.payment_method as method,
        CASE WHEN (o.paid_amount_cash > 0 OR o.paid_amount_online > 0) THEN 1 ELSE 0 END as is_split
    FROM orders o
    WHERE 
        o.created_at BETWEEN ? AND ?
        AND o.status != 'cancelled'
        AND o.payment_status = 'PAID'
        $branchFilterSql
    GROUP BY o.payment_method, 
             CASE WHEN (o.paid_amount_cash > 0 OR o.paid_amount_online > 0) THEN 1 ELSE 0 END
";

$stmtStats = $pdo->prepare($statsQueryItems);
$stmtStats->execute([$startDate, $endDate]);
$statsRows = $stmtStats->fetchAll(PDO::FETCH_ASSOC);

$totalCollected = 0;
$cashCollected = 0;
$onlineCollected = 0;

foreach ($statsRows as $row) {
    if ($row['is_split']) {
        $cashCollected += $row['sum_cash'];
        $onlineCollected += $row['sum_online'];
        $totalCollected += $row['sum_total'];
        continue;
    }

    $method = $row['method'];
    $total = $row['sum_total'];
    
    // payment_method is ENUM('COD','ONLINE','SPLIT') in current schema; treat unknown as cash.
    if ($method === 'ONLINE') $onlineCollected += $total;
    elseif ($method === 'COD') $cashCollected += $total;
    else { if ($total > 0) $cashCollected += $total; }
    $totalCollected += $total;
}

$hasTransactions = ($totalCollected > 0);

// 2. Cumulative Sales Upto Yesterday (use created_at range with index)
$queryUpto = "
    SELECT SUM(total) as total_upto FROM orders o
    WHERE o.created_at < ?
    AND o.status != 'cancelled' 
    AND o.payment_status = 'PAID'
    $branchFilterSql
";
$stmtUpto = $pdo->prepare($queryUpto);
$stmtUpto->execute([$startDate]);
$uptoYesterdayTotal = (float)($stmtUpto->fetch(PDO::FETCH_ASSOC)['total_upto'] ?? 0);

require_once __DIR__ . '/includes/header.php';
?>

<div class="container-fluid"
    style="padding: 20px; font-family: 'Inter', sans-serif; background: #f8f9fa; min-height: 100vh;">

    <!-- Compact Print Header -->
    <div class="print-header"
        style="display: none; border-bottom: 2px solid #000; padding-bottom: 12px; margin-bottom: 20px; position: relative;">
        <div style="display: flex; justify-content: space-between; align-items: baseline;">
            <h1 style="font-size: 20px; font-weight: 900; margin: 0; color: #000; text-transform: uppercase;">JustKleek</h1>
            <div style="font-size: 13px; font-weight: 700; color: #000;">
                DATE: <?php echo date('Y/m/d', strtotime($selectedDate)); ?>
            </div>
        </div>
        <div style="font-size: 12px; font-weight: 600; color: #444; margin-top: 4px;">
            Statement Of Operation (<?php echo date('H:i', strtotime($startDate)); ?> - <?php echo date('H:i', strtotime($endDate)); ?>)
        </div>
    </div>

    <!-- Compact Header -->
    <div class="screen-header-actual" style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 20px; font-family: 'Google Sans', 'Inter', Roboto, sans-serif; gap: 15px; flex-wrap: wrap;">
        <div>
            <h1 style="font-size: 22px; font-weight: 500; color: #202124; margin: 0 0 4px 0; letter-spacing: -0.3px;">
                Statement: <?php echo date('Y/m/d', strtotime($selectedDate)); ?>
            </h1>
            <p style="color: #5f6368; font-size: 13px; margin: 0;">
                Ref: Statement Of Operation Report
            </p>
        </div>
        
        <div style="display: flex; flex-direction: column; gap: 6px; align-items: flex-end;">
            <div style="background: #f8f9fa; border: 1px solid #dadce0; padding: 6px 12px; border-radius: 6px; font-size: 12px; color: #3c4043;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="font-weight: 600; color: #5f6368; font-size: 11px;">CYCLE:</span>
                    <span><?php echo date('Y/m/d H:i', strtotime($startDate)); ?></span>
                    <span style="color: #9aa0a6;">to</span>
                    <span><?php echo date('Y/m/d H:i', strtotime($endDate)); ?></span>
                </div>
            </div>

            <div style="background: #e8f0fe; border-left: 3px solid #1a73e8; padding: 6px 12px; border-radius: 4px 6px 6px 4px; display: flex; align-items: center; gap: 8px;">
                <div style="color: #1a73e8; display: flex; align-items: center;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="16" x2="12" y2="12"></line>
                        <line x1="12" y1="8" x2="12.01" y2="8"></line>
                    </svg>
                </div>
                <div style="font-size: 11.5px; color: #1a73e8;">
                    <strong style="font-weight: 600;">Audit Rule:</strong> Orders between 4 AM cycle grouped to <?php echo date('Y/m/d', strtotime($selectedDate)); ?>.
                </div>
            </div>
        </div>
    </div>

    <!-- Date Picker & Filter Row -->
    <style>
        .rider-date-group {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 8px 18px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            transition: all 0.2s ease;
        }

        .rider-date-group:hover {
            border-color: #6366f1;
            box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.1);
        }

        .rider-date-label {
            font-size: 11px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .rider-date-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .rider-date-input {
            appearance: none;
            -webkit-appearance: none;
            background: #eef2ff;
            border: 1.5px solid #c0ccff;
            padding: 10px 15px;
            padding-right: 40px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            color: #4338ca;
            cursor: pointer;
            transition: all 0.2s;
            outline: none;
            width: 180px;
            font-family: inherit;
        }

        .rider-date-input-wrapper::after {
            content: "";
            position: absolute;
            right: 15px;
            width: 18px;
            height: 18px;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236366f1' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='4' width='18' height='18' rx='2' ry='2'%3E%3C/rect%3E%3Cline x1='16' y1='2' x2='16' y2='6'%3E%3C/line%3E%3Cline x1='8' y1='2' x2='8' y2='6'%3E%3C/line%3E%3Cline x1='3' y1='10' x2='21' y2='10'%3E%3C/line%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: center;
            pointer-events: none;
        }

        .rider-date-input::-webkit-calendar-picker-indicator {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            margin: 0;
            padding: 0;
            cursor: pointer;
            opacity: 0;
        }

        /* --- Print Specific Styles --- */
        @media print {
            @page {
                size: portrait;
                margin: 10mm;
            }

            /* Hide UI elements */
            .admin-sidebar, .admin-header .header-right, .sidebar-toggle, .rider-topbar, .hamburger-btn, 
            .rider-date-group, .export-btn-container, .informational-alert, .admin-sidebar, 
            aside, #sidebarNav, .sidebar-header, .sidebar-nav, .sidebar-footer, .header-right,
            .screen-header-actual, .admin-actions-flex { 
                display: none !important; 
            }

            /* Reset container and body */
            body, .container-fluid { 
                background: white !important; 
                padding: 0 !important; 
                margin: 0 !important;
                width: 100% !important;
                box-sizing: border-box !important;
            }

            .admin-main { margin-left: 0 !important; padding: 0 !important; }

            /* Use 2-column grid for print to prevent horizontal cropping */
            .stats-grid-wrapper {
                display: grid !important;
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 15px !important;
                margin-bottom: 25px !important;
            }

            .stats-card {
                box-shadow: none !important;
                border: 1px solid #cbd5e1 !important;
                background: #ffffff !important;
                break-inside: avoid;
                padding: 12px !important;
            }

            .stats-card div {
                font-size: 16px !important;
                color: #000 !important;
            }

            .print-header {
                display: block !important;
            }

            /* Cleanup Table */
            #transactionsTableWrapper { display: block !important; box-shadow: none !important; border-radius: 0 !important; width: 100% !important; max-width: 100% !important; overflow: hidden !important; }
            table { width: 100% !important; border-top: 2px solid #000 !important; border-collapse: collapse !important; table-layout: fixed !important; }
            th { border-bottom: 2px solid #000 !important; color: #000 !important; padding: 8px 6px !important; text-align: left !important; font-size: 11px !important; }
            td { border-bottom: 1px solid #e2e8f0 !important; color: #000 !important; padding: 8px 6px !important; font-size: 11px !important; word-wrap: break-word !important; }
            
            h1 { color: #000 !important; }
            
            /* Give columns explicit proportions in print to avoid pushing right */
            th:nth-child(1), td:nth-child(1) { width: 15% !important; padding-left: 10px !important; }
            th:nth-child(2), td:nth-child(2) { width: 35% !important; }
            th:nth-child(3), td:nth-child(3) { width: 30% !important; }
            th:nth-child(4), td:nth-child(4) { width: 20% !important; text-align: right !important; padding-right: 25px !important; }

            /* Hide the receipt button column completely during print */
            th:nth-child(5), td:nth-child(5), th:last-child, td:last-child, .receipt-btn-col { 
                display: none !important; 
                width: 0 !important; 
                padding: 0 !important; 
                margin: 0 !important;
                border: none !important;
            }
        }
        .modal-open body {
            overflow: hidden;
        }

        .modal-open .admin-main {
            overflow: hidden !important;
        }
    </style>

    <div class="admin-actions-flex"
        style="display: flex; flex-wrap: wrap; gap: 15px; background: white; border-radius: 12px; padding: 15px 20px; align-items: center; justify-content: space-between; margin-bottom: 25px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <form method="GET" style="display: flex; align-items: center; gap: 15px; flex: 1;">
            <?php if (isset($_GET['branch'])): ?>
                <input type="hidden" name="branch" value="<?php echo htmlspecialchars($_GET['branch']); ?>">
            <?php endif; ?>
            <div class="rider-date-group">
                <span class="rider-date-label">Business Date:</span>
                <div class="rider-date-input-wrapper">
                    <input type="date" name="date" class="rider-date-input" value="<?php echo $selectedDate; ?>"
                        onchange="this.form.submit()">
                </div>
            </div>
        </form>

        <div class="export-btn-container">
            <button onclick="window.print()"
                style="background: #3e2723; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; font-size: 14px; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M6 9V2h12v7"></path>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                    <path d="M6 14h12v8H6z"></path>
                </svg>
                Export Statement
            </button>
        </div>
    </div>

    <!-- Stats Cards (Compact & Portable) -->
    <div class="stats-grid-wrapper"
        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 25px;">

        <!-- Total Upto Yesterday -->
        <div class="stats-card"
            style="background: linear-gradient(145deg, #ffffff, #f8fafc); border: 1px solid #e2e8f0; border-bottom: 4px solid #64748b; border-radius: 12px; padding: 16px; display: flex; align-items: center; gap: 14px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
            <div style="width: 44px; height: 44px; background: #ffffff; border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;">
                <img src="../assets/yesterday.png" alt="Yesterday" style="width: 28px; height: 28px; object-fit: contain;">
            </div>
            <div>
                <h3 style="font-size: 10px; color: #64748b; margin: 0 0 4px 0; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;">UPTO YESTERDAY</h3>
                <div style="font-size: 19px; font-weight: 900; color: #1e293b;">Rs. <?php echo number_format($uptoYesterdayTotal); ?></div>
            </div>
        </div>

        <!-- Total Collected -->
        <div class="stats-card"
            style="background: linear-gradient(145deg, #ffffff, #eff6ff); border: 1px solid #bfdbfe; border-bottom: 4px solid #3b82f6; border-radius: 12px; padding: 16px; display: flex; align-items: center; gap: 14px; box-shadow: 0 4px 6px -1px rgba(59,130,246,0.1);">
             <div style="width: 44px; height: 44px; background: #ffffff; border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(59,130,246,0.15); border: 1px solid #bfdbfe;">
                <img src="../assets/today.png" alt="Today" style="width: 28px; height: 28px; object-fit: contain;">
            </div>
            <div>
                <h3 style="font-size: 10px; color: #3b82f6; margin: 0 0 4px 0; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;">TOTAL TODAY</h3>
                <div style="font-size: 19px; font-weight: 900; color: #1e3a8a;">Rs. <?php echo number_format($totalCollected); ?></div>
            </div>
        </div>

        <!-- Cash Collected -->
        <div class="stats-card"
            style="background: linear-gradient(145deg, #ffffff, #f0fdf4); border: 1px solid #bbf7d0; border-bottom: 4px solid #22c55e; border-radius: 12px; padding: 16px; display: flex; align-items: center; gap: 14px; box-shadow: 0 4px 6px -1px rgba(34,197,94,0.1);">
            <div style="width: 44px; height: 44px; background: #ffffff; border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(34,197,94,0.15); border: 1px solid #bbf7d0;">
                <img src="../assets/cash.png" alt="Cash" style="width: 28px; height: 28px; object-fit: contain;">
            </div>
            <div>
                <h3 style="font-size: 10px; color: #16a34a; margin: 0 0 4px 0; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;">CASH</h3>
                <div style="font-size: 19px; font-weight: 900; color: #14532d;">Rs. <?php echo number_format($cashCollected); ?></div>
            </div>
        </div>

        <!-- Online Collected -->
        <div class="stats-card"
            style="background: linear-gradient(145deg, #ffffff, #faf5ff); border: 1px solid #e9d5ff; border-bottom: 4px solid #a855f7; border-radius: 12px; padding: 16px; display: flex; align-items: center; gap: 14px; box-shadow: 0 4px 6px -1px rgba(168,85,247,0.1);">
            <div style="width: 44px; height: 44px; background: #ffffff; border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 4px rgba(168,85,247,0.15); border: 1px solid #e9d5ff;">
                <img src="../assets/fonepay.png" alt="Fonepay" style="width: 28px; height: 28px; object-fit: contain;">
            </div>
            <div>
                <h3 style="font-size: 10px; color: #9333ea; margin: 0 0 4px 0; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;">ONLINE</h3>
                <div style="font-size: 19px; font-weight: 900; color: #581c87;">Rs. <?php echo number_format($onlineCollected); ?></div>
            </div>
        </div>
    </div>

    <!-- Itemized Sales Hidden Box -->
    <?php if ($hasTransactions): ?>
    <div id="showMoreContainer" style="margin: 20px 0; padding: 30px; border: 1px dashed #d1d5db; background: #fafafa; border-radius: 8px; text-align: center;">
        <p style="color: #64748b; font-size: 13px; margin: 0 0 15px 0; font-weight: 500;">
            Transaction details are hidden for performance.
        </p>
        <button id="viewItemizedBtn" onclick="showAllTransactions(this)" style="background: #8b4513; color: white; border: none; padding: 10px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 14px; transition: opacity 0.2s;">
            View Itemized Sales
        </button>
    </div>
    <?php endif; ?>

    <!-- Table -->
    <div id="transactionsTableWrapper" class="table-responsive" style="background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); overflow: hidden; display: none;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 2px solid #f1f5f9;">
                    <th style="text-align: left; padding: 15px 25px; font-size: 13px; font-weight: 600; color: #64748b;">Time</th>
                    <th style="text-align: left; padding: 15px 25px; font-size: 13px; font-weight: 600; color: #64748b;">Type / Customer</th>
                    <th style="text-align: left; padding: 15px 25px; font-size: 13px; font-weight: 600; color: #64748b;">Method</th>
                    <th style="text-align: right; padding: 15px 25px; font-size: 13px; font-weight: 600; color: #64748b;">Amount</th>
                    <th style="text-align: center; padding: 15px 25px; font-size: 13px; font-weight: 600; color: #64748b;">Receipt</th>
                </tr>
            </thead>
            <tbody id="ajaxTableBody">
                <!-- Rows load here dynamically -->
            </tbody>
        </table>
        
        <script>
            function showAllTransactions(btnElement) {
                // Change button state to loading
                const originalText = btnElement.innerText;
                btnElement.innerText = "Loading transactions...";
                btnElement.disabled = true;
                btnElement.style.opacity = "0.7";

                const urlParams = new URLSearchParams(window.location.search);
                const queryDate = urlParams.get('date') || '<?php echo $selectedDate; ?>';

                fetch('?date=' + queryDate + '&ajax_transactions=1')
                    .then(response => response.text())
                    .then(html => {
                        document.getElementById('ajaxTableBody').innerHTML = html;
                        document.getElementById('transactionsTableWrapper').style.display = 'block';
                        document.getElementById('showMoreContainer').style.display = 'none';
                    })
                    .catch(err => {
                        alert('Error loading transactions.');
                        btnElement.innerText = originalText;
                        btnElement.disabled = false;
                        btnElement.style.opacity = "1";
                    });
            }
        </script>
    </div>
</div>

<!-- Receipt Modal -->
<div id="receiptModal"
    style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div
        style="background: white; border-radius: 12px; width: 100%; max-width: 400px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
        <div
            style="padding: 15px 20px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 16px; font-weight: 700;">Customer Receipt</h3>
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

    function openReceipt(orderId) {
        const modal = document.getElementById('receiptModal');
        const content = document.getElementById('receiptContent');

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