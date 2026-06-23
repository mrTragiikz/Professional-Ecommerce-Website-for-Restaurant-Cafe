<?php
define('RiderAppCore', true);

// Set timezone at the very beginning for consistent date/time logic
$tz = 'Asia/Kathmandu';
@date_default_timezone_set($tz);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/RiderContext.php';

$ctx = RiderContext::resolve();
$riderId = $ctx['riderId'];
$contextMode = $ctx['contextMode'];
$resolverError = $ctx['resolverError'];

$isAdmin = ($contextMode === 'admin');

// Handle Unauthorized State
if (!$riderId) {
    if ($isAdmin || (isset($resolverError) && $resolverError === 'admin_auth_required')) {
        header('Location: ../admin.php?logout=1');
    } else {
        header('Location: login.php');
    }
    exit;
}

function jk_h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}


// Fetch display name for the rider
if ($riderId) {
    try {
        // Check existing name columns to ensure compatibility across different DB versions
        $colCheck = $pdo->query("SHOW COLUMNS FROM riders")->fetchAll(PDO::FETCH_COLUMN);
        $nameCol = in_array('full_name', $colCheck) ? 'full_name' : (in_array('name', $colCheck) ? 'name' : 'username');

        $stmt = $pdo->prepare("SELECT $nameCol as real_name FROM riders WHERE id = ?");
        $stmt->execute([$riderId]);
        $rData = $stmt->fetch();
        if ($rData) {
            $riderName = htmlspecialchars($rData['real_name']);
        }
    } catch (Exception $e) {
        $riderName = htmlspecialchars($_SESSION['rider_name'] ?? 'Rider');
    }
}

if (!$riderId) {
    header("Location: login.php");
    exit;
}

require '_header.php';

$now = time();
$cutoffTime = '04:00:00';
$shiftStartTime = '10:00:00';
// Now using the correctly set timezone
$nowTime = date('H:i:s');
// At exactly 4 AM, the working date flips to today.
$defaultDate = ($nowTime < $cutoffTime) ? date('Y-m-d', strtotime('yesterday')) : date('Y-m-d');
$viewDate = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : $defaultDate;

// Shift bounds exactly follow business rules: 10:00 AM to 03:59:59 AM next day
$startTime = $viewDate . ' ' . $shiftStartTime;
$endTime = date('Y-m-d', strtotime($viewDate . ' +1 day')) . ' 03:59:59';

$dailyStats = [
    'total_trips' => 0,
    'total_delivered' => 0,
    'total_km' => 0,
    'total_delivery_fees' => 0,
    'total_cash' => 0,
    'total_online' => 0,
    'total_tips' => 0,
];

try {
    // Standardize query to match managerider.php logic: 
    // total_trips = all orders assigned (Grabbed OR Delivered today)
    // delivered/km/fees = tied to completions in the window
    // Optimized query: Select 1000 orders first, then aggregate.
    // This is the "Life Time" performance fix for million-row databases.
    $stmt = $pdo->prepare("
        SELECT
            COUNT(o.id) AS total_trips,
            SUM(CASE WHEN o.status IN ('completed', 'received') AND o.delivered_at >= :s1 AND o.delivered_at <= :e1 THEN 1 ELSE 0 END) AS total_delivered,
            SUM(CASE WHEN o.status IN ('completed', 'received') AND o.delivered_at >= :s2 AND o.delivered_at <= :e2 THEN COALESCE(o.delivery_distance_km, 0) ELSE 0 END) AS total_km,
            SUM(CASE WHEN o.status IN ('completed', 'received') AND o.delivered_at >= :s3 AND o.delivered_at <= :e3 THEN COALESCE(o.delivery_fee, 0) ELSE 0 END) AS total_delivery_fees,
            SUM(CASE WHEN o.status = 'completed' AND o.delivered_at >= :s4 AND o.delivered_at <= :e4 AND LOWER(o.payment_status) = 'paid' THEN COALESCE(o.paid_amount_cash, 0) ELSE 0 END) AS total_cash,
            SUM(CASE WHEN o.status = 'completed' AND o.delivered_at >= :s5 AND o.delivered_at <= :e5 AND LOWER(o.payment_status) = 'paid' THEN COALESCE(o.paid_amount_online, 0) ELSE 0 END) AS total_online,
            SUM(CASE WHEN o.status IN ('completed', 'received') AND o.delivered_at >= :s6 AND o.delivered_at <= :e6 THEN COALESCE(o.rider_tip, 0) ELSE 0 END) AS total_tips
        FROM (
            SELECT id, status, delivered_at, delivery_distance_km, delivery_fee, payment_status, paid_amount_cash, paid_amount_online, rider_tip, grabbed_at
            FROM orders
            WHERE rider_id = :rider_id 
            AND (
                (grabbed_at >= :gs AND grabbed_at <= :ge) OR 
                (delivered_at >= :ds AND delivered_at <= :de)
            )
            LIMIT 1000
        ) o
    ");
    $stmt->execute([
        ':s1' => $startTime, ':e1' => $endTime,
        ':s2' => $startTime, ':e2' => $endTime,
        ':s3' => $startTime, ':e3' => $endTime,
        ':s4' => $startTime, ':e4' => $endTime,
        ':s5' => $startTime, ':e5' => $endTime,
        ':s6' => $startTime, ':e6' => $endTime,
        ':gs' => $startTime, ':ge' => $endTime,
        ':ds' => $startTime, ':de' => $endTime,
        ':rider_id' => $riderId
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $dailyStats['total_trips'] = (int) $row['total_trips'];
        $dailyStats['total_delivered'] = (int) $row['total_delivered'];
        $dailyStats['total_km'] = (float) ($row['total_km'] ?? 0);
        $dailyStats['total_delivery_fees'] = (float) ($row['total_delivery_fees'] ?? 0);
        $dailyStats['total_cash'] = (float) ($row['total_cash'] ?? 0);
        $dailyStats['total_online'] = (float) ($row['total_online'] ?? 0);
        $dailyStats['total_tips'] = (float) ($row['total_tips'] ?? 0);
    }
} catch (Exception $e) {
    // Keep defaults
}

$closing = null;
try {
    $cStmt = $pdo->prepare("SELECT * FROM rider_daily_closings WHERE rider_id = ? AND closing_date = ?");
    $cStmt->execute([$riderId, $viewDate]);
    $closing = $cStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {
}

// -------------------------------------------------------------
// MONTHLY STATS CALCULATION
// -------------------------------------------------------------
$currentMonth = date('Y-m', strtotime($viewDate));
$monthName = date('F Y', strtotime($viewDate));
$monthFirstDay = $currentMonth . '-01';
$monthLastDay = date('Y-m-t', strtotime($viewDate));

// Strict monthly bounds based on 10 AM to 4 AM shift logic
$monthStartTime = $monthFirstDay . ' 10:00:00';
$monthEndTime = date('Y-m-d', strtotime($monthLastDay . ' +1 day')) . ' 03:59:59';

$monthlyStats = [
    'total_delivered' => 0,
    'total_hired_km' => 0,
    'total_bike_km' => 0,
    'total_tips' => 0,
];

try {
    $mStmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN status = 'completed' AND delivered_at >= :s1 AND delivered_at <= :e1 THEN 1 ELSE 0 END) AS m_delivered,
            SUM(CASE WHEN status = 'completed' AND delivered_at >= :s2 AND delivered_at <= :e2 THEN COALESCE(delivery_distance_km, 0) ELSE 0 END) AS m_hired
        FROM (
            SELECT id, status, delivered_at, delivery_distance_km, grabbed_at
            FROM orders 
            WHERE rider_id = :rider_id AND (
                (grabbed_at >= :gs AND grabbed_at <= :ge) OR 
                (delivered_at >= :ds AND delivered_at <= :de)
            )
            LIMIT 5000
        ) o
    ");
    $mStmt->execute([
        ':s1' => $monthStartTime,
        ':e1' => $monthEndTime,
        ':s2' => $monthStartTime,
        ':e2' => $monthEndTime,
        ':gs' => $monthStartTime,
        ':ge' => $monthEndTime,
        ':ds' => $monthStartTime,
        ':de' => $monthEndTime,
        ':rider_id' => $riderId
    ]);
    $mRow = $mStmt->fetch(PDO::FETCH_ASSOC);
    if ($mRow) {
        $monthlyStats['total_delivered'] = (int) $mRow['m_delivered'];
        $monthlyStats['total_hired_km'] = (float) $mRow['m_hired'];
    }
} catch (Exception $e) {
}

try {
    $cStmt = $pdo->prepare("
        SELECT SUM(COALESCE(total_km, end_km - start_km, 0)) as m_bike_km
        FROM rider_daily_closings
        WHERE rider_id = ? AND closing_date >= ? AND closing_date <= ? AND start_km IS NOT NULL AND end_km IS NOT NULL
    ");
    $cStmt->execute([$riderId, $monthFirstDay, $monthLastDay]);
    $monthlyStats['total_bike_km'] = (float) $cStmt->fetchColumn();
} catch (Exception $e) {
}
// -------------------------------------------------------------

// 12-hour format (Nepali style: 1–12 with AM/PM). Use Asia/Kathmandu if available.
$tz = 'Asia/Kathmandu';
if (!@date_default_timezone_set($tz)) {
    $tz = date_default_timezone_get();
}
$now12 = date('g:i A', time());
?>
<?php $rider_current_page = 'my_details';
require __DIR__ . '/_sidebar.php'; ?>
<link rel="stylesheet" href="../rider_styles.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="rider_orders.css?v=<?php echo time(); ?>">
<style>
    body {
        padding-top: 80px !important;
    }
</style>
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

    <!-- Desktop Navigation (only shown on PC) -->
    <div class="rider-desktop-nav">
        <a href="daily_closing.php" class="desktop-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
            Bike Km Set
        </a>
        <a href="orders.php" class="desktop-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="1" y="3" width="15" height="13"></rect>
                <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
                <circle cx="5.5" cy="18.5" r="2.5"></circle>
                <circle cx="18.5" cy="18.5" r="2.5"></circle>
            </svg>
            Delivery
        </a>
        <a href="orders.php?filter=delivered" class="desktop-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
            </svg>
            Completed
        </a>
        <a href="my_details.php" class="desktop-link active">
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

    <span style="font-size:0.85rem; color:var(--text-muted);" id="clock"><?php echo jk_h($now12); ?></span>
</div>

<div class="rider-container" style="justify-content:flex-start; padding: 20px; max-width: 1000px; margin: 0 auto;">
    <div class="rider-card" style="width:100%; border:none; box-shadow: 0 4px 20px rgba(0,0,0,0.08);">
        <div class="profile-header-card">
            <div class="profile-info-section">
                <span style="font-size: 0.8rem; font-weight: 600; color: #64748b; letter-spacing: 0.02em;">Rider
                    Profile</span>
                <h2 class="stage-title" style="margin:4px 0 0 0; font-size:1.4rem; font-weight: 700; color: #334155;">
                    <?php echo $riderName; ?>
                </h2>
                <div style="display: flex; align-items: center; gap: 8px; margin-top: 10px;">
                    <div
                        style="width: 8px; height: 8px; background: #10b981; border-radius: 50%; box-shadow: 0 0 4px rgba(16, 185, 129, 0.4);">
                    </div>
                    <span style="font-size: 0.8rem; font-weight: 500; color: #475569;">Active Duty</span>
                </div>
            </div>

            <div class="date-selector-section">
                <label style="display: block; font-size:0.8rem; color:#64748b; font-weight: 600; margin-bottom: 8px;"
                    class="date-selector-label">
                    Audit & Performance Date
                </label>

                <div style="display: flex; flex-direction: column; gap: 12px;" class="date-controls-wrapper">
                    <div style="position: relative; width: 100%; max-width: 200px;" class="date-input-container">
                        <input type="date" value="<?php echo jk_h($viewDate); ?>"
                            onchange="const urlParams = new URLSearchParams(window.location.search); urlParams.set('date', this.value); window.location.search = urlParams.toString();"
                            style="appearance: none; -webkit-appearance: none; background: #ffffff; border: 1px solid #e2e8f0; padding: 12px 16px; padding-right: 44px; border-radius: 12px; font-size: 0.95rem; font-weight: 600; color: #1e293b; width: 100%; box-sizing: border-box; outline: none; transition: all 0.2s ease; cursor: pointer; box-shadow: 0 2px 4px rgba(0,0,0,0.02); font-family: inherit;"
                            class="custom-date-input">
                        <div
                            style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #6366f1;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- MONTHLY PERFORMANCE SECTION -->
    <h3 class="stage-action-title" style="font-weight: 700; color: #334155; margin-bottom: 20px;">
        Total Performance: <?php echo jk_h($monthName); ?>
    </h3>
    <div class="stage-stats-row performance-grid" style="margin-bottom: 30px;">
        <div class="stage-stat-card" style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);">
            <div class="stat-icon-wrap" style="color: #6366f1; background: #eef2ff;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
            </div>
            <div class="stage-stat-label">Total Bike Km</div>
            <div class="stage-stat-value" style="color: #1e293b;">
                <?php echo number_format($monthlyStats['total_bike_km'], 2); ?> km
            </div>
        </div>

        <div class="stage-stat-card" style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);">
            <div class="stat-icon-wrap" style="color: #f59e0b; background: #fffbeb;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                    <circle cx="12" cy="10" r="3"></circle>
                </svg>
            </div>
            <div class="stage-stat-label">Total Hired Km</div>
            <div class="stage-stat-value" style="color: #f59e0b;">
                <?php echo number_format($monthlyStats['total_hired_km'], 2); ?> km
            </div>
        </div>

        <div class="stage-stat-card" style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);">
            <div class="stat-icon-wrap" style="color: #10b981; background: #ecfdf5;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
            </div>
            <div class="stage-stat-label">Total Delivered</div>
            <div class="stage-stat-value" style="color: #10b981;">
                <?php echo (int) $monthlyStats['total_delivered']; ?>
            </div>
        </div>

        <div class="stage-stat-card" style="background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);">
            <div class="stat-icon-wrap" style="color: #4f46e5; background: #eef2ff;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <line x1="12" y1="1" x2="12" y2="23"></line>
                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                </svg>
            </div>
            <div class="stage-stat-label">Total Tips</div>
            <div class="stage-stat-value" style="color: #4f46e5;">
                Rs <?php echo number_format($monthlyStats['total_tips'], 2); ?>
            </div>
        </div>
    </div>

    <!-- DAILY LOG SECTION -->
    <h3 class="stage-action-title"
        style="font-weight: 700; color: #334155; margin-bottom: 20px; border-top: 1px dashed #cbd5e1; padding-top: 30px;">
        Daily Breakout: <?php echo jk_h(date('M j, Y', strtotime($viewDate))); ?>
    </h3>
    <div class="stage-stats-row performance-grid">
        <style>
            .performance-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            @media (min-width: 640px) {
                .performance-grid {
                    grid-template-columns: repeat(3, 1fr);
                    gap: 20px;
                }
            }

            @media (min-width: 1024px) {
                .performance-grid {
                    grid-template-columns: repeat(4, 1fr);
                }
            }

            .stage-stat-card {
                padding: 20px 16px !important;
                display: flex;
                flex-direction: column;
                align-items: center;
                text-align: center;
                background: #fff;
                border: 1.5px solid #e2e8f0 !important;
                border-radius: 16px !important;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05) !important;
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
                position: relative;
                overflow: hidden;
            }

            .stage-stat-label {
                font-size: 0.7rem !important;
                font-weight: 500 !important;
                color: #64748b !important;
                text-transform: uppercase !important;
                letter-spacing: 0.05em !important;
                margin-top: 14px !important;
            }

            .stage-stat-value {
                font-size: 1.3rem !important;
                font-weight: 600 !important;
                color: #1e293b !important;
                margin-top: 6px !important;
            }

            .clickable-stat-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 12px 24px -10px rgba(99, 102, 241, 0.3) !important;
                border-color: #6366f1 !important;
            }
        </style>
        <div class="stage-stat-card clickable-stat-card" style="cursor: pointer;"
            onclick="openGrabbedDetails('<?php echo $viewDate; ?>')">
            <div class="stat-icon-wrap" style="color: #6366f1; background: #eef2ff;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path
                        d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z">
                    </path>
                    <path d="m3.3 7 8.7 5 8.7-5"></path>
                    <path d="M12 22V12"></path>
                </svg>
            </div>
            <div class="stage-stat-label">Today Pick</div>
            <div class="stage-stat-value"><?php echo (int) ($dailyStats['total_trips'] ?? 0); ?></div>
        </div>
        <div class="stage-stat-card clickable-stat-card" style="cursor: pointer;"
            onclick="openDeliveredDetails('<?php echo $viewDate; ?>')">
            <div class="stat-icon-wrap" style="color: #10b981; background: #ecfdf5;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
            </div>
            <div class="stage-stat-label">Delivered</div>
            <div class="stage-stat-value"><?php echo (int) ($dailyStats['total_delivered'] ?? 0); ?></div>
        </div>
        <div class="stage-stat-card clickable-stat-card" style="cursor: pointer;"
            onclick="openHiredKmDetails('<?php echo $viewDate; ?>')">
            <div class="stat-icon-wrap" style="color: #f59e0b; background: #fffbeb;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                    <circle cx="12" cy="10" r="3"></circle>
                </svg>
            </div>
            <div class="stage-stat-label">Hired Km</div>
            <div class="stage-stat-value" style="color: #f59e0b;">
                <?php echo number_format((float) ($dailyStats['total_km'] ?? 0), 2); ?> km
            </div>
        </div>

        <div class="stage-stat-card clickable-stat-card" style="cursor: pointer;"
            onclick="openCashDetails('<?php echo $viewDate; ?>')">
            <div class="stat-icon-wrap" style="background: transparent;">
                <img src="../../assets/cashlogo.jpg" alt="Cash"
                    style="width:40px; height:40px; object-fit:contain; border-radius:8px;">
            </div>
            <div class="stage-stat-label">Cash Collect</div>
            <div class="stage-stat-value" style="color:#ef4444;">Rs
                <?php echo number_format((float) ($dailyStats['total_cash'] ?? 0), 2); ?>
            </div>
        </div>
        <div class="stage-stat-card clickable-stat-card" style="cursor: pointer;"
            onclick="openOnlineDetails('<?php echo $viewDate; ?>')">
            <div class="stat-icon-wrap" style="background: transparent;">
                <img src="../../assets/fonepay.png" alt="Fonepay"
                    style="width:40px; height:40px; object-fit:contain; border-radius:8px;">
            </div>
            <div class="stage-stat-label">Online Collect</div>
            <div class="stage-stat-value" style="color:#4f46e5;">Rs
                <?php echo number_format((float) ($dailyStats['total_online'] ?? 0), 2); ?>
            </div>
        </div>
        <!-- New Stat Boxes -->
        <div class="stage-stat-card clickable-stat-card" style="cursor: pointer;"
            onclick="openTipsDetails('<?php echo $viewDate; ?>')">
            <div class="stat-icon-wrap" style="color: #4f46e5; background: #eef2ff;">
                <span style="font-size: 15px; font-weight: 800;">Rs</span>
            </div>
            <div class="stage-stat-label">Tips</div>
            <div class="stage-stat-value" style="color: #4f46e5;">Rs
                <?php echo number_format((float) ($dailyStats['total_tips'] ?? 0), 2); ?>
            </div>
        </div>
        <div class="stage-stat-card">
            <div class="stat-icon-wrap" style="color: #8b5cf6; background: #f5f3ff;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
            </div>
            <div class="stage-stat-label">Starting Km</div>
            <div class="stage-stat-value">
                <?php echo ($closing && $closing['start_km']) ? number_format((float) $closing['start_km'], 2) : '0.00'; ?>
            </div>
        </div>
        <div class="stage-stat-card">
            <div class="stat-icon-wrap" style="color: #ec4899; background: #fdf2f8;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
            </div>
            <div class="stage-stat-label">Closing Km</div>
            <div class="stage-stat-value">
                <?php echo ($closing && $closing['end_km']) ? number_format((float) $closing['end_km'], 2) : '0.00'; ?>
            </div>
        </div>

        <div class="stage-stat-card">
            <div class="stat-icon-wrap" style="color: #4ade80; background: #f0fdf4;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
            </div>
            <div class="stage-stat-label">Starting Duty Time</div>
            <div class="stage-stat-value" style="font-size: 1rem !important; line-height: 1.4;">
                <?php echo ($closing && $closing['duty_start_time']) ? date('d M, h:i A', strtotime($closing['duty_start_time'])) : '–'; ?>
            </div>
        </div>

        <div class="stage-stat-card">
            <div class="stat-icon-wrap" style="color: #f87171; background: #fef2f2;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
            </div>
            <div class="stage-stat-label">Closing Duty Time</div>
            <div class="stage-stat-value" style="font-size: 1rem !important; line-height: 1.4;">
                <?php echo ($closing && $closing['duty_end_time']) ? date('d M, h:i A', strtotime($closing['duty_end_time'])) : '–'; ?>
            </div>
        </div>
        <div class="stage-stat-card" style="border-color: #10b981 !important; background: #f0fdf4;">
            <div class="stat-icon-wrap" style="color: #10b981; background: #dcfce7;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
            </div>
            <div class="stage-stat-label" style="color: #047857 !important;">Total Bike Km</div>
            <div class="stage-stat-value" style="color: #047857;">
                <?php 
                    $dailyBike = 0;
                    if ($closing && isset($closing['start_km']) && isset($closing['end_km'])) {
                        $dailyBike = (float)$closing['end_km'] - (float)$closing['start_km'];
                    }
                    echo number_format(max(0, $dailyBike), 2);
                ?> km
            </div>
        </div>
    </div>

</div>
</div>

<script>
    var _apiBase = '<?php echo $contextMode === "admin" ? "../api/rider_audit" : "api"; ?>';
    var _apiRiderId = '<?php echo $contextMode === "admin" ? "&rider_id=" . (int)$riderId : ""; ?>';

    (function () {
        function pad(n) { return n < 10 ? '0' + n : n; }
        function to12(d) {
            var h = d.getHours(), m = d.getMinutes();
            var ap = h >= 12 ? 'PM' : 'AM';
            h = h % 12; if (h === 0) h = 12;
            return h + ':' + pad(m) + ' ' + ap;
        }
        var el = document.getElementById('clock');
        if (el) setInterval(function () { el.textContent = to12(new Date()); }, 1000);
    })();

    function openGrabbedDetails(date) {
        const modal = document.getElementById('grabDetailsModal');
        const body = document.getElementById('grabDetailsBody');

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        body.innerHTML = '<div style="padding:40px; text-align:center; color:#64748b;"><div class="rider-loading-spinner" style="margin-bottom:12px;"></div>Loading assigned orders...</div>';

        fetch(_apiBase + '/get_grabbed_orders.php?date=' + date + _apiRiderId)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    body.innerHTML = data.html;
                } else {
                    body.innerHTML = '<div style="padding:40px; text-align:center; color:#ef4444;">Error loading details: ' + data.error + '</div>';
                }
            })
            .catch(err => {
                body.innerHTML = '<div style="padding:40px; text-align:center; color:#ef4444;">Connection failed</div>';
            });
    }

    function openDeliveredDetails(date) {
        const modal = document.getElementById('deliveredDetailsModal');
        const body = document.getElementById('deliveredDetailsBody');

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        body.innerHTML = '<div style="padding:40px; text-align:center; color:#64748b;"><div class="rider-loading-spinner" style="margin-bottom:12px;"></div>Loading delivered orders...</div>';

        fetch(_apiBase + '/get_delivered_orders.php?date=' + date + _apiRiderId)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    body.innerHTML = data.html;
                } else {
                    body.innerHTML = '<div style="padding:40px; text-align:center; color:#ef4444;">Error loading details: ' + data.error + '</div>';
                }
            })
            .catch(err => {
                body.innerHTML = '<div style="padding:40px; text-align:center; color:#ef4444;">Connection failed</div>';
            });
    }

    function openHiredKmDetails(date) {
        const modal = document.getElementById('hiredKmDetailsModal');
        const body = document.getElementById('hiredKmDetailsBody');

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        body.innerHTML = '<div style="padding:40px; text-align:center; color:#64748b;"><div class="rider-loading-spinner" style="margin-bottom:12px;"></div>Loading Hired KM details...</div>';

        fetch(_apiBase + '/get_hired_km.php?date=' + date + _apiRiderId)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    body.innerHTML = data.html;
                } else {
                    body.innerHTML = '<div style="padding:40px; text-align:center; color:#ef4444;">Error loading details: ' + data.error + '</div>';
                }
            })
            .catch(err => {
                body.innerHTML = '<div style="padding:40px; text-align:center; color:#ef4444;">Connection failed</div>';
            });
    }

    function openTipsDetails(date) {
        const modal = document.getElementById('tipsDetailsModal');
        const body = document.getElementById('tipsDetailsBody');

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        body.innerHTML = '<div style="padding:40px; text-align:center; color:#64748b;"><div class="rider-loading-spinner" style="margin-bottom:12px;"></div>Loading Tips details...</div>';

        fetch(_apiBase + '/get_tips_details.php?date=' + date + _apiRiderId)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    body.innerHTML = data.html;
                } else {
                    body.innerHTML = '<div style="padding:40px; text-align:center; color:#ef4444;">Error loading details: ' + data.error + '</div>';
                }
            })
            .catch(err => {
                body.innerHTML = '<div style="padding:40px; text-align:center; color:#ef4444;">Connection failed</div>';
            });
    }

    function openCashDetails(date) {
        const modal = document.getElementById('cashDetailsModal');
        const body = document.getElementById('cashDetailsBody');

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        body.innerHTML = '<div style="padding:40px; text-align:center; color:#64748b;"><div class="rider-loading-spinner" style="margin-bottom:12px;"></div>Loading Cash Collection details...</div>';

        fetch(_apiBase + '/get_cash_collect.php?date=' + date + _apiRiderId)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    body.innerHTML = data.html;
                } else {
                    body.innerHTML = '<div style="padding:40px; text-align:center; color:#ef4444;">Error loading details: ' + data.error + '</div>';
                }
            })
            .catch(err => {
                body.innerHTML = '<div style="padding:40px; text-align:center; color:#ef4444;">Connection failed</div>';
            });
    }

    function openOnlineDetails(date) {
        const modal = document.getElementById('onlineDetailsModal');
        const body = document.getElementById('onlineDetailsBody');

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        body.innerHTML = '<div style="padding:40px; text-align:center; color:#64748b;"><div class="rider-loading-spinner" style="margin-bottom:12px;"></div>Loading Online Collection details...</div>';

        fetch(_apiBase + '/get_online_collect.php?date=' + date + _apiRiderId)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    body.innerHTML = data.html;
                } else {
                    body.innerHTML = '<div style="padding:40px; text-align:center; color:#ef4444;">Error loading details: ' + data.error + '</div>';
                }
            })
            .catch(err => {
                body.innerHTML = '<div style="padding:40px; text-align:center; color:#ef4444;">Connection failed</div>';
            });
    }

    function viewOrderDetails(orderId, canPay = false) {
        const modal = document.getElementById('orderDetailModal');
        const content = document.getElementById('orderDetailContent');
        const label = document.getElementById('modal-order-id-label');

        label.innerText = orderId;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        content.innerHTML = '<div style="padding:40px; text-align:center; color:#64748b;"><div class="rider-loading-spinner" style="margin-bottom:12px;"></div>Loading order information...</div>';

        fetch(_apiBase + '/get_order_details.php?order_id=' + orderId + '&can_pay=' + (canPay ? 1 : 0) + _apiRiderId)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    content.innerHTML = data.html;
                } else {
                    content.innerHTML = `<div style="padding:40px; text-align:center; color:#ef4444; font-weight:700;">${data.error}</div>`;
                }
            })
            .catch(err => {
                content.innerHTML = '<div style="padding:40px; text-align:center; color:#ef4444; font-weight:700;">Connection failed</div>';
            });
    }

    function closeModal() {
        document.getElementById('orderDetailModal').classList.remove('active');
        if (!document.querySelector('.edit-modal.active')) {
            document.body.style.overflow = '';
        }
    }

    function closeGrabModal() {
        document.getElementById('grabDetailsModal').classList.remove('active');
        if (!document.querySelector('.edit-modal.active')) {
            document.body.style.overflow = '';
        }
    }

    function closeDeliveredModal() {
        document.getElementById('deliveredDetailsModal').classList.remove('active');
        if (!document.querySelector('.edit-modal.active')) {
            document.body.style.overflow = '';
        }
    }

    function closeHiredKmModal() {
        document.getElementById('hiredKmDetailsModal').classList.remove('active');
        if (!document.querySelector('.edit-modal.active')) {
            document.body.style.overflow = '';
        }
    }

    function closeCashModal() {
        document.getElementById('cashDetailsModal').classList.remove('active');
        if (!document.querySelector('.edit-modal.active')) {
            document.body.style.overflow = '';
        }
    }

    function closeOnlineModal() {
        document.getElementById('onlineDetailsModal').classList.remove('active');
        if (!document.querySelector('.edit-modal.active')) {
            document.body.style.overflow = '';
        }
    }

    function closeTipsModal() {
        document.getElementById('tipsDetailsModal').classList.remove('active');
        if (!document.querySelector('.edit-modal.active')) {
            document.body.style.overflow = '';
        }
    }
</script>

<!-- Grab Details Modal -->
<div id="grabDetailsModal" class="edit-modal">
    <div class="edit-modal-backdrop" onclick="closeGrabModal()"></div>
    <div class="edit-modal-content"
        style="max-width: 600px; padding: 0; overflow: hidden; border-radius: 24px; max-height: 90vh; display: flex; flex-direction: column;">
        <div class="edit-modal-header"
            style="padding: 20px 24px; margin: 0; background: #ffffff; border-bottom: 1px solid #f1f5f9; position: sticky; top: 0; z-index: 10; display: flex; justify-content: space-between; align-items: flex-start; flex-shrink: 0;">
            <div>
                <h3 class="edit-modal-title" style="margin: 0; font-size: 1.2rem; font-weight: 800;">Picked Orders</h3>
                <p style="margin: 4px 0 0 0; font-size: 0.8rem; color: #64748b;">Detailed list for the selected business
                    date</p>
            </div>
            <button class="edit-modal-close" onclick="closeGrabModal()"
                style="background: #f8fafc; color: #64748b;">&times;</button>
        </div>
        <div id="grabDetailsBody" style="padding: 16px; background: #f8fafc; overflow-y: auto; flex-grow: 1;">
        </div>
        <div
            style="padding: 16px 24px; background: #ffffff; border-top: 1px solid #f1f5f9; text-align: center; flex-shrink: 0;">
            <button class="rider-btn" onclick="closeGrabModal()"
                style="min-height: 44px; margin: 0; background: #1e293b;">Close Overview</button>
        </div>
    </div>
</div>

<!-- Delivered Details Modal -->
<div id="deliveredDetailsModal" class="edit-modal">
    <div class="edit-modal-backdrop" onclick="closeDeliveredModal()"></div>
    <div class="edit-modal-content"
        style="max-width: 600px; padding: 0; overflow: hidden; border-radius: 24px; max-height: 90vh; display: flex; flex-direction: column;">
        <div class="edit-modal-header"
            style="padding: 24px; margin: 0; background: #ffffff; border-bottom: 1px solid #f1f5f9; position: sticky; top: 0; z-index: 10; display: flex; justify-content: space-between; align-items: flex-start; flex-shrink: 0;">
            <div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <div
                        style="width:36px; height:36px; border-radius:10px; background:#ecfdf5; color:#10b981; display:flex; align-items:center; justify-content:center;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                            <polyline points="22 4 12 14.01 9 11.01"></polyline>
                        </svg>
                    </div>
                    <div>
                        <h3 class="edit-modal-title"
                            style="margin: 0; font-size: 1.25rem; font-weight: 600; color: #1e293b; letter-spacing: -0.02em;">
                            Delivered Orders</h3>
                        <p style="margin: 4px 0 0 0; font-size: 0.85rem; color: #64748b;">Completed log for this
                            business date</p>
                    </div>
                </div>
            </div>
            <button class="edit-modal-close" onclick="closeDeliveredModal()"
                style="background: #f8fafc; color: #64748b; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: none; font-size: 1.2rem;">&times;</button>
        </div>
        <div id="deliveredDetailsBody" style="padding: 20px; background: #f8fafc; overflow-y: auto; flex-grow: 1;">
        </div>
        <div
            style="padding: 16px 24px; background: #ffffff; border-top: 1px solid #f1f5f9; text-align: center; flex-shrink: 0;">
            <button class="rider-btn" onclick="closeDeliveredModal()"
                style="min-height: 48px; margin: 0; background: #1e293b; color: #fff; border: none; border-radius: 12px; width: 100%; font-weight: 700; font-size: 1rem; cursor: pointer; transition: all 0.2s;"
                onmouseover="this.style.background='#0f172a'" onmouseout="this.style.background='#1e293b'">Close
                Overview</button>
        </div>
    </div>
</div>

<!-- Hired Km Details Modal -->
<div id="hiredKmDetailsModal" class="edit-modal">
    <div class="edit-modal-backdrop" onclick="closeHiredKmModal()"></div>
    <div class="edit-modal-content"
        style="max-width: 600px; padding: 0; overflow: hidden; border-radius: 24px; max-height: 90vh; display: flex; flex-direction: column;">
        <div class="edit-modal-header"
            style="padding: 24px; margin: 0; background: #ffffff; border-bottom: 1px solid #f1f5f9; position: sticky; top: 0; z-index: 10; display: flex; justify-content: space-between; align-items: flex-start; flex-shrink: 0;">
            <div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <div
                        style="width:36px; height:36px; border-radius:10px; background:#fffbeb; color:#f59e0b; display:flex; align-items:center; justify-content:center;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5">
                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                            <circle cx="12" cy="10" r="3"></circle>
                        </svg>
                    </div>
                    <div>
                        <h3 class="edit-modal-title"
                            style="margin: 0; font-size: 1.25rem; font-weight: 600; color: #1e293b; letter-spacing: -0.02em;">
                            Hired KM Log</h3>
                        <p style="margin: 4px 0 0 0; font-size: 0.85rem; color: #64748b;">Distance covered on delivered
                            orders</p>
                    </div>
                </div>
            </div>
            <button class="edit-modal-close" onclick="closeHiredKmModal()"
                style="background: #f8fafc; color: #64748b; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: none; font-size: 1.2rem;">&times;</button>
        </div>
        <div id="hiredKmDetailsBody" style="padding: 20px; background: #f8fafc; overflow-y: auto; flex-grow: 1;">
        </div>
        <div
            style="padding: 16px 24px; background: #ffffff; border-top: 1px solid #f1f5f9; text-align: center; flex-shrink: 0;">
            <button class="rider-btn" onclick="closeHiredKmModal()"
                style="min-height: 48px; margin: 0; background: #1e293b; color: #fff; border: none; border-radius: 12px; width: 100%; font-weight: 700; font-size: 1rem; cursor: pointer; transition: all 0.2s;"
                onmouseover="this.style.background='#0f172a'" onmouseout="this.style.background='#1e293b'">Close
                Overview</button>
        </div>
    </div>
</div>

<!-- Cash Details Modal -->
<div id="cashDetailsModal" class="edit-modal">
    <div class="edit-modal-backdrop" onclick="closeCashModal()"></div>
    <div class="edit-modal-content"
        style="max-width: 600px; padding: 0; overflow: hidden; border-radius: 24px; max-height: 90vh; display: flex; flex-direction: column;">
        <div class="edit-modal-header"
            style="padding: 24px; margin: 0; background: #ffffff; border-bottom: 1px solid #f1f5f9; position: sticky; top: 0; z-index: 10; display: flex; justify-content: space-between; align-items: flex-start; flex-shrink: 0;">
            <div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <div
                        style="width:36px; height:36px; border-radius:10px; background:#fef2f2; color:#ef4444; display:flex; align-items:center; justify-content:center;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5">
                            <rect x="2" y="6" width="20" height="12" rx="2"></rect>
                            <circle cx="12" cy="12" r="2"></circle>
                            <path d="M6 12h.01M18 12h.01"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="edit-modal-title"
                            style="margin: 0; font-size: 1.25rem; font-weight: 600; color: #1e293b; letter-spacing: -0.02em;">
                            Cash Collection Log</h3>
                        <p style="margin: 4px 0 0 0; font-size: 0.85rem; color: #64748b;">Physical currency received on
                            delivery</p>
                    </div>
                </div>
            </div>
            <button class="edit-modal-close" onclick="closeCashModal()"
                style="background: #f8fafc; color: #64748b; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: none; font-size: 1.2rem;">&times;</button>
        </div>
        <div id="cashDetailsBody" style="padding: 20px; background: #f8fafc; overflow-y: auto; flex-grow: 1;">
        </div>
        <div
            style="padding: 16px 24px; background: #ffffff; border-top: 1px solid #f1f5f9; text-align: center; flex-shrink: 0;">
            <button class="rider-btn" onclick="closeCashModal()"
                style="min-height: 48px; margin: 0; background: #1e293b; color: #fff; border: none; border-radius: 12px; width: 100%; font-weight: 700; font-size: 1rem; cursor: pointer; transition: all 0.2s;"
                onmouseover="this.style.background='#0f172a'" onmouseout="this.style.background='#1e293b'">Close
                Overview</button>
        </div>
    </div>
</div>

<!-- Online Details Modal -->
<div id="onlineDetailsModal" class="edit-modal">
    <div class="edit-modal-backdrop" onclick="closeOnlineModal()"></div>
    <div class="edit-modal-content"
        style="max-width: 600px; padding: 0; overflow: hidden; border-radius: 24px; max-height: 90vh; display: flex; flex-direction: column;">
        <div class="edit-modal-header"
            style="padding: 24px; margin: 0; background: #ffffff; border-bottom: 1px solid #f1f5f9; position: sticky; top: 0; z-index: 10; display: flex; justify-content: space-between; align-items: flex-start; flex-shrink: 0;">
            <div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <div
                        style="width:36px; height:36px; border-radius:10px; background:#eef2ff; color:#4f46e5; display:flex; align-items:center; justify-content:center;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5">
                            <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                            <line x1="2" y1="10" x2="22" y2="10"></line>
                        </svg>
                    </div>
                    <div>
                        <h3 class="edit-modal-title"
                            style="margin: 0; font-size: 1.25rem; font-weight: 600; color: #1e293b; letter-spacing: -0.02em;">
                            Online Collection Log</h3>
                        <p style="margin: 4px 0 0 0; font-size: 0.85rem; color: #64748b;">Digital payments received
                            securely</p>
                    </div>
                </div>
            </div>
            <button class="edit-modal-close" onclick="closeOnlineModal()"
                style="background: #f8fafc; color: #64748b; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: none; font-size: 1.2rem;">&times;</button>
        </div>
        <div id="onlineDetailsBody" style="padding: 20px; background: #f8fafc; overflow-y: auto; flex-grow: 1;">
        </div>
        <div
            style="padding: 16px 24px; background: #ffffff; border-top: 1px solid #f1f5f9; text-align: center; flex-shrink: 0;">
            <button class="rider-btn" onclick="closeOnlineModal()"
                style="min-height: 48px; margin: 0; background: #1e293b; color: #fff; border: none; border-radius: 12px; width: 100%; font-weight: 700; font-size: 1rem; cursor: pointer; transition: all 0.2s;"
                onmouseover="this.style.background='#0f172a'" onmouseout="this.style.background='#1e293b'">Close
                Overview</button>
        </div>
    </div>
</div>

<!-- Tips Details Modal -->
<div id="tipsDetailsModal" class="edit-modal">
    <div class="edit-modal-backdrop" onclick="closeTipsModal()"></div>
    <div class="edit-modal-content"
        style="max-width: 600px; padding: 0; overflow: hidden; border-radius: 24px; max-height: 90vh; display: flex; flex-direction: column;">
        <div class="edit-modal-header"
            style="padding: 24px; margin: 0; background: #ffffff; border-bottom: 1px solid #f1f5f9; position: sticky; top: 0; z-index: 10; display: flex; justify-content: space-between; align-items: flex-start; flex-shrink: 0;">
            <div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <div
                        style="width:36px; height:36px; border-radius:10px; background:#eef2ff; color:#4f46e5; display:flex; align-items:center; justify-content:center;">
                        <span style="font-size: 16px; font-weight: 800;">Rs</span>
                    </div>
                    <div>
                        <h3 class="edit-modal-title"
                            style="margin: 0; font-size: 1.25rem; font-weight: 600; color: #1e293b; letter-spacing: -0.02em;">
                            Tips Collection Log</h3>
                        <p style="margin: 4px 0 0 0; font-size: 0.85rem; color: #64748b;">Extra tips earned on
                            deliveries</p>
                    </div>
                </div>
            </div>
            <button class="edit-modal-close" onclick="closeTipsModal()"
                style="background: #f8fafc; color: #64748b; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: none; font-size: 1.2rem;">&times;</button>
        </div>
        <div id="tipsDetailsBody" style="padding: 20px; background: #f8fafc; overflow-y: auto; flex-grow: 1;">
        </div>
        <div
            style="padding: 16px 24px; background: #ffffff; border-top: 1px solid #f1f5f9; text-align: center; flex-shrink: 0;">
            <button class="rider-btn" onclick="closeTipsModal()"
                style="min-height: 48px; margin: 0; background: #1e293b; color: #fff; border: none; border-radius: 12px; width: 100%; font-weight: 700; font-size: 1rem; cursor: pointer; transition: all 0.2s;"
                onmouseover="this.style.background='#0f172a'" onmouseout="this.style.background='#1e293b'">Close
                Overview</button>
        </div>
    </div>
</div>

<!-- Modal for Order Details -->
<div id="orderDetailModal" class="edit-modal">
    <div class="edit-modal-backdrop" onclick="closeModal()"></div>
    <div class="edit-modal-content"
        style="max-width: 600px; padding: 0; overflow: hidden; border-radius: 24px; max-height: 90vh; display: flex; flex-direction: column;">
        <div class="edit-modal-header"
            style="padding: 20px 24px; margin: 0; background: #ffffff; border-bottom: 1px solid #f1f5f9; position: sticky; top: 0; z-index: 10; display: flex; justify-content: space-between; align-items: flex-start; flex-shrink: 0;">
            <div>
                <h3 style="margin:0; font-weight: 800; color: #1e293b; font-size: 1.2rem;">Order Receipt #<span
                        id="modal-order-id-label"></span></h3>
                <p style="margin: 4px 0 0 0; font-size: 0.8rem; color: #64748b;">Detailed transaction view</p>
            </div>
            <button class="edit-modal-close" onclick="closeModal()"
                style="background: #f8fafc; color: #64748b;">&times;</button>
        </div>
        <div class="modal-body" id="orderDetailContent"
            style="padding: 16px; background: #f8fafc; overflow-y: auto; flex-grow: 1;">
            <!-- Details loaded via AJAX -->
        </div>
        <div
            style="padding: 16px 24px; background: #ffffff; border-top: 1px solid #f1f5f9; text-align: center; flex-shrink: 0;">
            <button class="rider-btn" onclick="closeModal()"
                style="min-height: 44px; margin: 0; background: #1e293b; width: 100%; border: none; border-radius: 12px; color: white; display:flex; align-items:center; justify-content:center; font-weight:800;cursor:pointer;">Close
                Details</button>
        </div>
    </div>
</div>


<style>
    .edit-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: 2000;
        align-items: center;
        justify-content: center;
        padding: 0;
    }

    .edit-modal.active {
        display: flex;
    }

    .edit-modal-backdrop {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.85); /* Solid for performance */
    }

    .edit-modal-content {
        position: relative;
        background: white;
        width: 100%;
        max-width: 600px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        animation: modalSlideUp 0.3s cubic-bezier(0.165, 0.84, 0.44, 1);
        border-radius: 24px;
        align-self: center;
        margin: 20px;
        will-change: transform, opacity;
        backface-visibility: hidden;
    }

    @media (min-width: 640px) {
        .edit-modal {
            padding: 20px;
        }

        .edit-modal-content {
            border-radius: 24px;
            align-self: center;
            margin: 0;
        }
    }

    @keyframes modalSlideUp {
        from {
            transform: translateY(100%);
            opacity: 0.5;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .edit-modal-close {
        border: none;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        cursor: pointer;
        transition: all 0.2s;
    }

    .edit-modal-close:hover {
        background: #f1f5f9 !important;
        transform: rotate(90deg);
    }

    .rider-loading-spinner {
        width: 32px;
        height: 32px;
        border: 4px solid #f1f5f9;
        border-top: 4px solid #6366f1;
        border-radius: 50%;
        margin: 0 auto;
        animation: spin 1s cubic-bezier(0.5, 0, 0.5, 1) infinite;
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }

    /* Rider Profile Header Layout */
    .profile-header-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        padding: 24px;
        border-radius: 20px;
        margin-bottom: 24px;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
        display: flex;
        flex-direction: column;
        gap: 24px;
    }

    .profile-info-section {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
    }

    .date-selector-section {
        display: flex;
        flex-direction: column;
        align-items: center;
        /* Center items for mobile */
    }

    .date-selector-label {
        text-align: center;
    }

    .date-controls-wrapper {
        align-items: center;
    }

    .date-btn-group {
        justify-content: center;
    }

    .date-input-container {
        margin: 0 auto;
    }

    /* Desktop View */
    @media (min-width: 640px) {
        .profile-header-card {
            flex-direction: row;
            justify-content: space-between;
            align-items: center;
        }

        .date-selector-section {
            align-items: flex-end;
            /* Right-align items for desktop */
        }

        .date-selector-label {
            text-align: right;
        }

        .date-controls-wrapper {
            align-items: flex-end;
        }

        .date-btn-group {
            justify-content: flex-end;
        }
    }

    .custom-date-input::-webkit-calendar-picker-indicator {
        opacity: 0;
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        width: 100%;
        height: 100%;
        cursor: pointer;
    }

    .custom-date-input:focus {
        border-color: #6366f1 !important;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1) !important;
    }
</style>

<?php require '_footer.php'; ?>