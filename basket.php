<?php
/**
 * Basket Page - Items and Checkout
 */

// Security initialization (must be first)
require_once __DIR__ . '/app/functions/security_init.php';

require_once __DIR__ . '/app/functions/auth.php';



// Load User Cart

if (isUserLoggedIn()) {
    // Load cart from database for logged-in users
    require_once __DIR__ . '/config/db.php';
    $user = getCurrentUser();
    $userId = $user['id'] ?? null;

    if ($userId) {
        try {
            if (isset($pdo) && $pdo !== null) {
                // Fetch cart items from database
                $stmt = $pdo->prepare("
                    SELECT item_id, item_name, item_description, quantity, unit_price, item_image 
                    FROM cart_items 
                    WHERE user_id = ? 
                    ORDER BY updated_at DESC
                ");
                $stmt->execute([$userId]);
                $dbCartItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Convert database format to session format
                $dbCart = [];
                foreach ($dbCartItems as $dbItem) {
                    $dbCart[] = [
                        'id' => $dbItem['item_id'],
                        'name' => $dbItem['item_name'],
                        'description' => $dbItem['item_description'] ?? '',
                        'price' => floatval($dbItem['unit_price']),
                        'quantity' => intval($dbItem['quantity']),
                        'image' => $dbItem['item_image'] ?? 'assets/plate.png'
                    ];
                }

                // Sync database cart to session
                if (!empty($dbCart)) {
                    $_SESSION['cart'] = $dbCart;
                } elseif (!isset($_SESSION['cart'])) {
                    $_SESSION['cart'] = [];
                }
            } else {
                if (!isset($_SESSION['cart'])) {
                    $_SESSION['cart'] = [];
                }
            }
        } catch (Throwable $e) {
            error_log("Basket cart load error: " . $e->getMessage());
            if (!isset($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }
        }
    }
} else {
    // Initialize empty cart for guests
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
}



// Get base path for asset URLs
$basePath = getBasePath();

// Load operating hours settings
require_once __DIR__ . '/config/load_security.php';
// Check user verification status
$isLoggedIn = isUserLoggedIn();
$user = $isLoggedIn ? getCurrentUser() : null;
$isVerified = $isLoggedIn && $user && ($user['is_verified'] ?? 0);

$selectedBranchId = getCurrentCustomerBranchId();

$restaurantSettings = getRestaurantSettings($selectedBranchId);
$openingTime = $restaurantSettings['opening_time'] ?: '11:00'; // Fallback to 11AM
$closingTime = $restaurantSettings['closing_time'] ?: '02:00'; // Fallback to 2AM
$restaurantTimezone = $restaurantSettings['timezone'] ?: 'Asia/Kathmandu';
$isClosedManual = $restaurantSettings['is_closed'];



// Cart operations handler

/**
 * Function to save cart to database for logged-in users
 */
function syncCartToDb($cart, $userId)
{
    if (!$userId)
        return false;
    require_once __DIR__ . '/config/db.php';
    global $pdo;

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("DELETE FROM cart_items WHERE user_id = ?");
        $stmt->execute([$userId]);

        if (!empty($cart)) {
            $stmt = $pdo->prepare("INSERT INTO cart_items (user_id, item_id, item_name, item_description, quantity, unit_price, item_image) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($cart as $item) {
                $stmt->execute([
                    $userId,
                    $item['id'] ?? uniqid('item_', true),
                    $item['name'],
                    $item['description'] ?? '',
                    intval($item['quantity']),
                    floatval($item['price']),
                    $item['image'] ?? ''
                ]);
            }
        }
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        error_log("Cart sync error: " . $e->getMessage());
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // Add item to cart
    if ($_POST['action'] === 'add') {
        // Get the actual price from database (don't trust client-side price)
        $itemName = htmlspecialchars(trim($_POST['name']));
        $actualPrice = 0;

        try {
            if (isset($pdo) && $pdo !== null) {
                $tableCheck = $pdo->query("SHOW TABLES LIKE 'menu_items'")->fetch();

                if ($tableCheck) {
                    $stmt = $pdo->prepare("SELECT price FROM menu_items WHERE item_name = ? AND is_active = 1 LIMIT 1");
                    $stmt->execute([$itemName]);
                    $menuItem = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($menuItem) {
                        $actualPrice = floatval($menuItem['price']);
                    } else {
                        $actualPrice = floatval(preg_replace('/[^\d.]/', '', str_replace('Rs.', '', $_POST['price'] ?? 0)));
                    }
                } else {
                    $actualPrice = floatval(preg_replace('/[^\d.]/', '', str_replace('Rs.', '', $_POST['price'] ?? 0)));
                }
            } else {
                $actualPrice = floatval(preg_replace('/[^\d.]/', '', str_replace('Rs.', '', $_POST['price'] ?? 0)));
            }
        } catch (Throwable $e) {
            $actualPrice = floatval(preg_replace('/[^\d.]/', '', str_replace('Rs.', '', $_POST['price'] ?? 0)));
        }

        $item = [
            'id' => $_POST['id'] ?? uniqid('item_', true),
            'name' => $itemName,
            'description' => $_POST['description'] ?? '',
            'price' => $actualPrice,
            'quantity' => intval($_POST['quantity'] ?? 1),
            'image' => $_POST['image'] ?? ''
        ];

        // ---------------------------------------------------------
        // STOCK VALIDATION
        // ---------------------------------------------------------
        try {
            $stockStmt = $pdo->prepare("
                SELECT m.stock_count, c.category_name 
                FROM menu_items m 
                JOIN menu_categories c ON m.category_id = c.id 
                WHERE m.item_name = ?
            ");
            $stockStmt->execute([$itemName]);
            $stockData = $stockStmt->fetch(PDO::FETCH_ASSOC);

            if ($stockData) {
                $availableStock = (int) $stockData['stock_count'];
                $tracked_categories = ['Groceries', 'Justkleek Drinks', 'JustKleek Drinks', 'Soft drinks', 'Soft Drinks', 'Grocery', 'Grocerys', 'Beer Selection'];

                if (in_array($stockData['category_name'], $tracked_categories)) {
                    $totalRequested = $item['quantity'];
                    // Check existing items in cart
                    if (isset($_SESSION['cart'])) {
                        foreach ($_SESSION['cart'] as $cartItem) {
                            if ($cartItem['name'] === $itemName) {
                                $totalRequested += $cartItem['quantity'];
                            }
                        }
                    }

                    if ($totalRequested > $availableStock) {
                        echo json_encode(['success' => false, 'error' => "Sorry, we only have {$availableStock} of '{$itemName}' left in stock."]);
                        exit;
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Stock check error in add: " . $e->getMessage());
        }
        // ---------------------------------------------------------

        // Check if item already exists to merge
        $itemExists = false;
        if (isset($_SESSION['cart'])) {
            foreach ($_SESSION['cart'] as &$cartItem) {
                if ($cartItem['name'] === $itemName && $cartItem['price'] === $actualPrice) {
                    $cartItem['quantity'] += $item['quantity'];
                    $itemExists = true;
                    break;
                }
            }
        }

        if (!$itemExists) {
            $_SESSION['cart'][] = $item;
        }

        // Release session lock immediately
        session_write_close();

        // Sync to database if logged in
        if ($isLoggedIn && $userId) {
            syncCartToDb($_SESSION['cart'], $userId);
        }

        echo json_encode(['success' => true, 'cart_count' => count($_SESSION['cart'])]);
        exit;
    }

    if ($_POST['action'] === 'update') {
        $index = intval($_POST['index']);
        $quantity = intval($_POST['quantity']);
        if (isset($_SESSION['cart'][$index])) {
            if ($quantity <= 0) {
                // Remove item if quantity is 0 or less
                unset($_SESSION['cart'][$index]);
                $_SESSION['cart'] = array_values($_SESSION['cart']); // Re-index array
            } else {
                // ---------------------------------------------------------
                // STOCK VALIDATION (MODERN & SECURE)
                // ---------------------------------------------------------
                try {
                    $itemName = $_SESSION['cart'][$index]['name'];
                    // Check current stock in database
                    $stockStmt = $pdo->prepare("
                        SELECT m.stock_count, m.track_stock, c.category_name 
                        FROM menu_items m 
                        JOIN menu_categories c ON m.category_id = c.id 
                        WHERE m.item_name = ?
                    ");
                    $stockStmt->execute([$itemName]);
                    $stockData = $stockStmt->fetch(PDO::FETCH_ASSOC);

                    if ($stockData) {
                        $availableStock = (int) $stockData['stock_count'];

                        // Check if category is tracked
                        $categoryName = $stockData['category_name'];
                        $tracked_categories = ['Groceries', 'Justkleek Drinks', 'JustKleek Drinks', 'Soft drinks', 'Soft Drinks', 'Grocery', 'Grocerys', 'Beer Selection'];
                        $shouldTrackStock = in_array($categoryName, $tracked_categories);

                        if ($shouldTrackStock) {
                            // Validation: Check if requested quantity exceeds available stock
                            if ($quantity > $availableStock) {
                                echo json_encode([
                                    'success' => false,
                                    'error' => "Sorry, we only have {$availableStock} of '{$itemName}' left in stock."
                                ]);
                                exit;
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log("Stock check error in update: " . $e->getMessage());
                }
                // ---------------------------------------------------------

                $_SESSION['cart'][$index]['quantity'] = $quantity;
            }

            // Release session lock immediately
            session_write_close();

            // Sync to database if logged in
            if ($isLoggedIn && $userId) {
                syncCartToDb($_SESSION['cart'], $userId);
            }
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($_POST['action'] === 'remove') {
        $index = intval($_POST['index']);
        if (isset($_SESSION['cart'][$index])) {
            unset($_SESSION['cart'][$index]);
            $_SESSION['cart'] = array_values($_SESSION['cart']); // Re-index array

            // Release session lock immediately
            session_write_close();

            // Sync to database if logged in
            if ($isLoggedIn && $userId) {
                syncCartToDb($_SESSION['cart'], $userId);
            }
        }
        echo json_encode(['success' => true]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Order Basket | JustKleek</title>
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/navbar.css?v=1.0.1">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/animations.css?v=1.0.1">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/assets/css/dv_mobile_nav_v3.css?v=<?php echo time(); ?>">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>

    <script src="<?php echo $basePath; ?>/js/prevent-zoom.js"></script>
    <script src="<?php echo $basePath; ?>/js/click-sound.js"></script>
    <style>
        /* Global HD Text & Font Settings */
        body,
        html,
        button,
        input,
        select,
        textarea,
        .store-clock-label,
        .status-text,
        .timer-label,
        .store-operating-hours {
            font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;
            text-rendering: optimizeLegibility !important;
            -webkit-font-smoothing: antialiased !important;
            -moz-osx-font-smoothing: grayscale !important;
        }

        /* Keep fancy font only for specific decorative headings if needed, usually h1/h2 */
        h1,
        h2,
        .modal-header h3 {
            font-family: 'Plus Jakarta Sans', sans-serif !important;
            /* Overriding Garamond for cleaner look as requested */
            letter-spacing: -0.5px;
        }

        /* Location Error Modal Styles (Injected locally due to file lock) */
        .location-error-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 12000;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }

        .location-error-modal.active {
            opacity: 1;
            visibility: visible;
        }

        .location-error-backdrop {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }

        .location-error-content {
            position: relative;
            background: white;
            padding: 40px;
            border-radius: 24px;
            width: 90%;
            max-width: 420px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            transform: translateY(20px) scale(0.95);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            border: 1px solid rgba(255, 82, 82, 0.1);
        }

        .location-error-modal.active .location-error-content {
            transform: translateY(0) scale(1);
        }

        .location-error-icon {
            margin-bottom: 24px;
            display: flex;
            justify-content: center;
        }

        .error-icon-wrapper {
            position: relative;
            width: 90px;
            height: 90px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .error-circle-outer {
            position: absolute;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: rgba(255, 82, 82, 0.1);
            animation: ripple 2s infinite;
        }

        .error-circle-middle {
            position: absolute;
            width: 70%;
            height: 70%;
            border-radius: 50%;
            background: rgba(255, 82, 82, 0.15);
        }

        .error-exclamation {
            position: relative;
            z-index: 2;
            filter: drop-shadow(0 4px 6px rgba(255, 82, 82, 0.3));
            animation: shakeIcon 0.5s ease-in-out 0.3s;
        }

        .location-error-title {
            font-size: 24px;
            font-weight: 700;
            color: var(--black);
            margin-bottom: 12px;
            font-family: 'Cormorant Garamond', serif;
        }

        .location-error-message {
            font-size: 15px;
            color: var(--gray);
            line-height: 1.6;
            margin-bottom: 32px;
        }

        .location-error-actions {
            display: flex;
            justify-content: center;
        }

        .location-error-btn {
            padding: 14px 40px;
            background: #FF5252;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 8px 16px rgba(255, 82, 82, 0.25);
            min-width: 140px;
        }

        .location-error-btn:hover {
            background: #ff3333;
            transform: translateY(-2px);
            box-shadow: 0 12px 20px rgba(255, 82, 82, 0.35);
        }

        .location-error-btn:active {
            transform: translateY(0);
        }

        @keyframes shakeIcon {

            0%,
            100% {
                transform: rotate(0deg);
            }

            20% {
                transform: rotate(-10deg);
            }

            40% {
                transform: rotate(10deg);
            }

            60% {
                transform: rotate(-5deg);
            }

            80% {
                transform: rotate(5deg);
            }
        }

        @keyframes ripple {
            0% {
                transform: scale(1);
                opacity: 0.6;
            }

            100% {
                transform: scale(1.5);
                opacity: 0;
            }
        }

        /* Red Theme Overrides for Store Status (User Request) */
        /* Premium Store Status Widget */
        /* Premium Store Status Widget */
        .store-status {
            background: #ffffff !important;
            border: 1px solid rgba(226, 232, 240, 0.8) !important;
            box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.05) !important;
            border-radius: 24px !important;
            padding: 8px !important;
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            animation: cardEntrance 0.8s cubic-bezier(0.2, 0.8, 0.2, 1);
        }

        .store-status:hover {
            transform: translateY(-4px);
            box-shadow: 0 30px 60px -12px rgba(59, 130, 246, 0.15) !important;
            /* Blue glow */
            border-color: rgba(59, 130, 246, 0.3) !important;
        }

        @keyframes cardEntrance {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Status Section Wrapper */
        .status-section-wrapper {
            background: #f8fafc;
            border-radius: 20px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .status-indicator {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
        }

        .status-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 100px;
            font-weight: 700;
            font-size: 14px;
            letter-spacing: 0.5px;
            position: relative;
            overflow: hidden;
        }

        .status-badge::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0.1;
            background: currentColor;
        }

        .status-text {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            /* Green Gradient default */
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            color: #10b981;
            position: relative;
            z-index: 1;
            font-weight: 800;
        }

        .status-icon {
            color: #10b981 !important;
            /* Green connection/lock */
            position: relative;
            z-index: 1;
            animation: iconPulse 2s infinite ease-in-out;
        }

        @keyframes iconPulse {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.1);
            }

            100% {
                transform: scale(1);
            }
        }

        /* WOW Clock Styles */
        /* WOW Clock Styles */
        .store-status-clock {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%) !important;
            /* Soft Blue Gradient */
            border: 1px solid rgba(59, 130, 246, 0.1) !important;
            box-shadow:
                0 10px 30px rgba(59, 130, 246, 0.05),
                inset 0 0 0 1px rgba(255, 255, 255, 0.8) !important;
            border-radius: 20px !important;
            padding: 24px !important;
            position: relative;
            overflow: hidden;
            transition: transform 0.3s ease;
        }

        .store-status-clock:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 40px rgba(227, 24, 55, 0.08) !important;
        }

        .store-clock-label {
            color: #1e293b !important;
            /* Dark Slate to match theme */
            font-size: 13px !important;
            font-weight: 600 !important;
            letter-spacing: 1.5px !important;
            text-transform: uppercase !important;
            margin-bottom: 15px !important;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .store-clock-label::before,
        .store-clock-label::after {
            content: '';
            height: 1px;
            width: 30px;
            background: #000000;
            opacity: 0.1;
        }

        .store-clock-display {
            display: flex;
            align-items: baseline;
            justify-content: center;
            font-variant-numeric: tabular-nums;
            font-feature-settings: "tnum";
            position: relative;
        }

        .store-clock-hours,
        .store-clock-minutes {
            font-family: 'Plus Jakarta Sans', sans-serif !important;
            font-size: 56px !important;
            font-weight: 900 !important;
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%) !important;
            /* Deep Blue Gradient */
            -webkit-background-clip: text !important;
            -webkit-text-fill-color: transparent !important;
            color: transparent !important;
            /* Fallback */
            text-shadow: 0 4px 12px rgba(59, 130, 246, 0.15) !important;
            min-width: 70px;
            text-align: center;
        }

        /* Separate color for seconds to make it 'live' */
        /* Separate color for seconds to make it 'live' */
        .store-clock-seconds {
            font-size: 32px !important;
            background: linear-gradient(135deg, #f43f5e 0%, #fb7185 100%) !important;
            /* Vibrant Coral */
            -webkit-background-clip: text !important;
            -webkit-text-fill-color: transparent !important;
            color: transparent !important;
            min-width: 45px;
            margin-left: 5px;
            animation: secondPop 1s infinite cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-block;
        }

        @keyframes secondPop {
            0% {
                transform: scale(1);
                opacity: 1;
            }

            50% {
                transform: scale(1.05);
                opacity: 0.8;
            }

            100% {
                transform: scale(1);
                opacity: 1;
            }
        }

        .store-clock-separator {
            font-size: 32px !important;
            color: #000000 !important;
            opacity: 0.2;
            margin: 0 4px;
            animation: pulse 1s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 0.2;
            }

            50% {
                opacity: 1;
            }
        }

        .store-clock-period {
            font-size: 14px !important;
            font-weight: 700 !important;
            color: #000000 !important;
            margin-left: 8px;
            background: #f1f5f9;
            padding: 4px 8px;
            border-radius: 6px;
            transform: translateY(-20px);
        }


        .store-operating-hours {
            margin-top: 10px;
            padding-top: 20px;
            border-top: 1px dashed rgba(0, 0, 0, 0.1);
            font-size: 15px;
            /* Bigger font */
            color: #475569;
            /* Darker Slate */
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.5);
            padding: 10px;
            border-radius: 12px;
        }

        .store-operating-hours svg {
            width: 22px;
            height: 22px;
            color: #6366f1;
            /* Indigo icon */
            animation: liveSpin 4s linear infinite;
            /* Smooth continuous rotation */
            transform-origin: center center;
        }

        @keyframes liveSpin {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }

        .store-operating-hours strong {
            font-weight: 800;
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
            /* Indigo to Violet */
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            color: #4f46e5;
        }

        /* Countdown Timer Redesign */
        .status-timer-container {
            display: flex;
            flex-direction: column;
            /* Stack vertically */
            align-items: center;
            justify-content: center;
            gap: 12px;
            /* Better gap */
            background: white;
            padding: 20px 16px;
            /* Reset padding for breathing room */
            border-radius: 14px;
            border: 1px solid #f1f5f9;
        }

        .timer-wrapper {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .timer-label {
            color: #0f172a !important;
            font-size: 24px;
            /* Keep it big */
            font-weight: 900;
            /* Boldest */
            text-transform: uppercase;
            letter-spacing: -1px;
            line-height: 1;
            margin: 0;
            /* Reset margins */
        }

        .timer-target-time {
            font-size: 14px;
            font-weight: 600;
            color: #64748b;
            margin-left: 8px;
            text-transform: uppercase;
        }

        .status-timer {
            display: flex;
            align-items: center;
            gap: 6px;
            color: #10b981 !important;
            /* Emerald */
            font-weight: 800;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 16px;
        }

        .status-timer span {
            background: #ecfdf5 !important;
            /* Light Emerald Bg */
            color: #059669 !important;
            /* Dark Emerald Text */
            padding: 4px 8px;
            border-radius: 6px;
            min-width: 28px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(16, 185, 129, 0.1);
        }

        .operating-hours-link {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 14px;
            background: #f8fafc;
            color: #3b82f6 !important;
            /* Blue */
            font-weight: 700;
            border-radius: 12px;
            text-decoration: none;
            transition: all 0.2s ease;
            font-size: 14px;
        }

        .operating-hours-link svg {
            color: #3b82f6 !important;
            stroke: #3b82f6 !important;
            transition: all 0.2s ease;
        }

        .operating-hours-link:hover {
            background: #eff6ff !important;
            /* Light Blue Bg */
            color: #2563eb !important;
            /* Darker Blue */
        }

        .operating-hours-link:hover svg {
            color: #2563eb !important;
            stroke: #2563eb !important;
        }


        /* Service Toggle Styles */
        .service-toggle {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            background: #f8f9fa;
            padding: 6px;
            border-radius: 16px;
            border: 1px solid #eee;
        }

        .service-btn {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px;
            border: none;
            border-radius: 12px;
            background: transparent;
            color: #666;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .service-btn svg {
            transition: transform 0.3s ease;
        }

        .service-btn:hover:not(.active) {
            background: rgba(255, 68, 68, 0.05);
            color: #ff4444;
        }

        .service-btn.active {
            background: white;
            color: #ff4444;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        .service-btn.active svg {
            transform: scale(1.1);
        }


        /* Desktop Adjustment: Move Order Button Down */
        @media (min-width: 769px) {
            .order-now-btn {
                margin-top: 20px !important;
            }
        }

        /* Professional Operating Hours Modal */
        .operating-hours-close {
            position: absolute;
            top: 20px;
            right: 20px;
            background: none;
            border: none;
            font-size: 32px;
            color: #666;
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.2s ease;
            z-index: 10;
        }

        .operating-hours-close:hover {
            background: #f5f5f5;
            color: #333;
        }

        .operating-hours-header {
            padding: 30px 60px 20px 30px;
            border-bottom: 1px solid #e0e0e0;
        }

        .operating-hours-title {
            margin: 0;
            font-size: 22px;
            font-weight: 600;
            color: #333;
        }

        .operating-hours-display {
            padding: 40px 30px;
        }

        .hours-simple-message {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }

        .hours-icon {
            font-size: 28px;
        }

        .hours-text h3 {
            margin: 0 0 5px 0;
            font-size: 16px;
            font-weight: 500;
            color: #666;
        }

        .hours-time {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            color: #333;
            letter-spacing: 0.5px;
        }

        @media (max-width: 768px) {
            .operating-hours-close {
                top: 15px;
                right: 15px;
                font-size: 28px;
                width: 36px;
                height: 36px;
            }

            .operating-hours-header {
                padding: 25px 50px 15px 20px;
            }

            .operating-hours-title {
                font-size: 20px;
            }

            .status-badge {
                padding: 6px 12px;
                font-size: 13px;
            }

            .hours-simple-message {
                flex-direction: row;
                gap: 12px;
                padding: 18px;
            }

            .hours-icon {
                font-size: 24px;
            }

            .hours-text h3 {
                font-size: 14px;
            }

            .hours-time {
                font-size: 18px;
            }

        }

        @media (max-width: 480px) {
            .store-status-clock {
                padding: 15px !important;
            }

            .store-clock-label {
                font-size: 12px !important;
            }

            .store-clock-display {
                font-size: 26px !important;
                gap: 4px;
            }

            font-size: 12px !important;
            margin-left: 2px;
        }

        /* Timer Adjustments for Mobile */
        .timer-label {
            font-size: 18px !important;
            /* Slightly smaller on mobile */
            letter-spacing: -0.5px !important;
            margin: 0 !important;
        }

        .status-timer-container {
            padding: 16px 12px !important;
            gap: 8px !important;
        }
        }

        /* ================================================================
           FRIEND / FAMILY DELIVERY FEATURE STYLES
           ================================================================ */

        /* Checkbox Row */
        .friend-order-toggle-wrapper {
            padding: 16px 20px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            transition: all 0.25s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            margin-bottom: 16px;
            margin-top: 4px;
        }
        .friend-order-toggle-wrapper:hover {
            border-color: #cbd5e1;
            background: #f8fafc;
        }
        .friend-order-toggle-wrapper.is-active {
            border-color: #f43f5e;
            background: #fffafa;
            box-shadow: 0 4px 12px rgba(244, 63, 94, 0.05);
        }
        .friend-order-checkbox-label {
            display: flex;
            align-items: center;
            gap: 14px;
            cursor: pointer;
            user-select: none;
        }
        /* Custom checkbox visual */
        .friend-checkbox-visual {
            flex-shrink: 0;
            width: 22px;
            height: 22px;
            border-radius: 6px;
            border: 2px solid #cbd5e1;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .friend-order-toggle-wrapper:hover .friend-checkbox-visual:not(.checked) {
            border-color: #94a3b8;
        }
        .friend-checkbox-visual.checked {
            background: #f43f5e;
            border-color: #f43f5e;
        }
        .friend-checkbox-visual.checked #friendCheckIcon {
            opacity: 1 !important;
            transform: scale(1) !important;
        }
        .friend-checkbox-text {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .friend-checkbox-title {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 15px;
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
            letter-spacing: -0.1px;
        }
        .friend-checkbox-sub {
            font-size: 13px;
            color: #64748b;
            font-weight: 400;
            line-height: 1.4;
        }

        /* Main Panel Card */
        .friend-delivery-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            animation: friendPanelSlideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes friendPanelSlideIn {
            from { opacity: 0; transform: translateY(-8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Card Header */
        .friend-delivery-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 20px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 15px;
            font-weight: 600;
            color: #1e293b;
        }
        .friend-delivery-header-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            background: white;
            border-radius: 8px;
            color: #f43f5e;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }

        /* Tab Switcher */
        .friend-location-tabs {
            display: flex;
            gap: 6px;
            padding: 14px 18px 0;
        }
        .friend-tab-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            background: #f8fafc;
            color: #64748b;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .friend-tab-btn:hover:not(.active) {
            border-color: #fda4af;
            color: #f43f5e;
            background: #fff5f6;
        }
        .friend-tab-btn.active {
            background: linear-gradient(135deg, #f43f5e 0%, #e11d48 100%);
            border-color: #f43f5e;
            color: white;
            box-shadow: 0 4px 10px rgba(244, 63, 94, 0.25);
        }

        /* Inputs */
        .friend-location-input {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 15px;
            color: #1e293b;
            background: #ffffff;
            outline: none;
            transition: all 0.2s ease;
            box-sizing: border-box;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.02);
        }
        .friend-location-input:focus {
            border-color: #f43f5e;
            box-shadow: 0 0 0 3px rgba(244, 63, 94, 0.1);
        }
        .friend-input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
        }

        /* Field label */
        .friend-field-label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            text-transform: none;
            margin-bottom: 8px;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        /* Select dropdown */
        .friend-select-input {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 15px;
            color: #1e293b;
            background: #ffffff;
            outline: none;
            transition: all 0.2s ease;
            box-sizing: border-box;
            cursor: pointer;
            appearance: auto;
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }
        .friend-select-input:focus {
            border-color: #f43f5e;
            box-shadow: 0 0 0 3px rgba(244, 63, 94, 0.1);
        }

        /* Use Current Location button */
        .friend-use-location-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            flex-shrink: 0;
            padding: 12px 16px;
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 14px;
            font-weight: 600;
            color: #475569;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .friend-use-location-btn:hover {
            border-color: #f43f5e;
            color: #f43f5e;
            background: #fffafa;
        }


        /* Geocode/Calculate button */
        .friend-geocode-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 14px;
            background: #f43f5e;
            color: white;
            border: none;
            border-radius: 8px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(244, 63, 94, 0.2);
        }
        .friend-geocode-btn:hover {
            background: #e11d48;
            box-shadow: 0 4px 6px rgba(244, 63, 94, 0.25);
            transform: translateY(-1px);
        }
        .friend-geocode-btn:active {
            transform: translateY(0);
        }
        .friend-geocode-btn:disabled {
            background: #cbd5e1;
            box-shadow: none;
            cursor: not-allowed;
            transform: none;
        }

        /* Suggestions dropdown */
        .friend-suggestions-dropdown {
            position: absolute;
            top: calc(100% + 4px);
            left: 0;
            right: 0;
            z-index: 200;
            background: white;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.12);
            overflow: hidden;
            max-height: 220px;
            overflow-y: auto;
        }
        .friend-suggestion-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 11px 14px;
            cursor: pointer;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 13px;
            color: #1e293b;
            border-bottom: 1px solid #f1f5f9;
            transition: background 0.15s ease;
        }
        .friend-suggestion-item:last-child { border-bottom: none; }
        .friend-suggestion-item:hover { background: #fff5f6; }
        .friend-suggestion-icon {
            flex-shrink: 0;
            width: 24px;
            height: 24px;
            background: #fff1f2;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #f43f5e;
            margin-top: 1px;
        }
        .friend-suggestion-main { font-weight: 600; color: #0f172a; }
        .friend-suggestion-sub  { font-size: 11px; color: #94a3b8; margin-top: 1px; }

        /* Fee Result Card */
        .friend-fee-result-card {
            margin: 0 18px 16px;
            padding: 16px;
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1.5px solid #86efac;
            border-radius: 16px;
            animation: friendResultPop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        @keyframes friendResultPop {
            from { opacity: 0; transform: scale(0.95); }
            to   { opacity: 1; transform: scale(1); }
        }
        .friend-fee-result-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        .friend-fee-label {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .friend-fee-addr {
            font-size: 13px;
            font-weight: 600;
            color: #15803d;
            font-family: 'Plus Jakarta Sans', sans-serif;
            flex: 1;
        }
        .friend-fee-details-grid {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            background: white;
            border-radius: 12px;
            border: 1px solid #bbf7d0;
            overflow: hidden;
            margin-bottom: 12px;
        }
        .friend-fee-detail-item {
            flex: 1;
            text-align: center;
            padding: 12px 8px;
        }
        .friend-fee-detail-divider {
            width: 1px;
            height: 44px;
            background: #bbf7d0;
        }
        .friend-fee-detail-val {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 18px;
            font-weight: 800;
            color: #1e293b;
            margin-bottom: 2px;
        }
        .friend-fee-detail-val.highlight {
            background: linear-gradient(135deg, #f43f5e 0%, #e11d48 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            color: #f43f5e;
        }
        .friend-fee-detail-key {
            font-size: 11px;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .friend-fee-applied-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 700;
            color: #16a34a;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }

        /* Spinner */
        .friend-spinner {
            width: 36px;
            height: 36px;
            border: 3px solid #fecdd3;
            border-top-color: #f43f5e;
            border-radius: 50%;
            animation: friendSpin 0.8s linear infinite;
            margin: 0 auto;
        }
        @keyframes friendSpin { to { transform: rotate(360deg); } }

        /* Error */
        .friend-fee-error {
            margin: 0 18px 16px;
            padding: 12px 14px;
            background: #fff1f2;
            border: 1.5px solid #fecdd3;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            color: #e11d48;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Notice & Instruction Link */
        .friend-input-notice {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 8px;
            font-size: 11px;
            color: #64748b;
            font-weight: 500;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        .see-instruction-link {
            color: #f43f5e;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 4px;
            font-weight: 700;
            transition: all 0.2s ease;
        }
        .see-instruction-link:hover {
            color: #e11d48;
            text-decoration: underline;
        }

        /* Instruction Modal */
        .instruction-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 20000;
            display: flex;
            justify-content: center;
            align-items: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .instruction-modal.active {
            opacity: 1;
            visibility: visible;
        }
        .instruction-modal-backdrop {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .instruction-modal.active .instruction-modal-backdrop {
            opacity: 1;
        }
        .instruction-modal-content {
            position: relative;
            background: #fff;
            width: 90%;
            max-width: 380px;
            border-radius: 24px;
            padding: 20px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            transform: translateY(60px) scale(0.95);
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            z-index: 1;
        }
        .instruction-modal.active .instruction-modal-content {
            transform: translateY(30px) scale(1);
        }
        .instruction-modal-close {
            display: none;
        }

        /* Bottom Close Button for Instruction Modal */
        .instruction-modal-footer {
            padding-top: 8px;
            display: flex;
            justify-content: center;
        }

        .instruction-bottom-close-btn {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #f43f5e 0%, #e11d48 100%);
            color: white;
            border: none;
            border-radius: 16px;
            font-size: 15px;
            font-weight: 700;
            font-family: 'Plus Jakarta Sans', sans-serif;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 10px 20px -5px rgba(244, 63, 94, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            position: relative;
            overflow: hidden;
        }

        .instruction-bottom-close-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 15px 25px -5px rgba(244, 63, 94, 0.4);
            background: linear-gradient(135deg, #e11d48 0%, #be123c 100%);
        }

        .instruction-bottom-close-btn:active {
            transform: translateY(0);
        }

        .instruction-bottom-close-btn svg {
            transition: transform 0.3s ease;
        }

        .instruction-bottom-close-btn:hover svg {
            transform: rotate(90deg);
        }

        /* Result area spacing */
        #friendFeeResult, #friendFeeSpinner, #friendFeeError {
            margin-top: 4px;
        }
    </style>
    <?php require_once __DIR__ . '/includes/meta_pixel.php'; ?>
</head>

<body class="basket-page">
    <!-- Navigation Bar -->
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <!-- Order Basket Container -->
    <div class="basket-container">
        <div class="basket-wrapper">
            <div class="basket-header">
                <h1 class="basket-title">Order Basket</h1>
                <a href="<?php echo $basePath; ?>/menu" class="back-to-menu-btn">
                    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M11.25 13.5L6.75 9L11.25 4.5" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span>Back to Menu</span>
                </a>
            </div>

            <!-- Desktop Two-Column Layout -->
            <div class="basket-layout">
                <!-- Left Column: Order Details -->
                <div class="basket-left-column">
                    <!-- Service Toggle -->
                    <!-- Service Toggle Removed - Delivery Only -->
                    <div class="service-toggle" style="display: none;"></div>

                    <!-- Service Times Cards -->




                    <!-- Store Status with Clock -->
                    <div class="store-status">
                        <!-- Status Indicator (Outside Clock Box) -->
                        <div class="status-indicator" style="justify-content: center; margin-bottom: 12px;">
                            <svg class="status-icon" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                xmlns="http://www.w3.org/2000/svg" style="margin-right: 5px;">
                                <path
                                    d="M19 11H5C3.89543 11 3 11.8954 3 13V20C3 21.1046 3.89543 22 5 22H19C20.1046 22 21 21.1046 21 20V13C21 11.8954 20.1046 11 19 11Z"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round" />
                                <path
                                    d="M7 11V7C7 5.67392 7.52678 4.40215 8.46447 3.46447C9.40215 2.52678 10.6739 2 12 2C13.3261 2 14.5979 2.52678 15.5355 3.46447C16.4732 4.40215 17 5.67392 17 7V11"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round" />
                            </svg>
                            <span class="status-text" id="statusText" style="font-size: 16px;">We're Currently
                                Closed</span>
                        </div>

                        <!-- Live Clock Display (Moved to Top) -->
                        <div class="store-status-clock">


                            <div class="store-clock-label">Current Time (Nepal)</div>
                            <div class="store-clock-display" id="storeStatusClock">
                                <span class="store-clock-hours">00</span>
                                <span class="store-clock-separator">:</span>
                                <span class="store-clock-minutes">00</span>
                                <span class="store-clock-separator">:</span>
                                <span class="store-clock-seconds">00</span>
                                <span class="store-clock-period">AM</span>
                            </div>
                            <div class="store-operating-hours">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <polyline points="12 6 12 12 16 14"></polyline>
                                </svg>
                                <span>Restro Hours:
                                    <strong><?php echo date('g:i A', strtotime($openingTime)); ?></strong> -
                                    <strong><?php echo date('g:i A', strtotime($closingTime)); ?></strong></span>
                            </div>


                        </div>



                        <div class="status-timer-container">
                            <span class="timer-label" id="timerLabel">OPENS IN:</span>
                            <div class="timer-wrapper">
                                <span class="status-timer" id="openingTimer"><span>06</span> : <span>05</span> :
                                    <span>37</span></span>
                                <span class="timer-target-time" id="targetTimeDisplay"></span>
                            </div>
                        </div>

                    </div>







                    <!-- Your Order Section -->
                    <div class="order-items-section">
                        <!-- Delivery Address Section -->
                        <div class="delivery-address-section" style="margin-bottom: 24px;">
                            <div
                                style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                <label
                                    style="font-weight: 800; font-size: 16px; color: #1e293b; display: flex; align-items: center; gap: 8px; margin: 0; font-family: 'Plus Jakarta Sans', sans-serif;">
                                    <span
                                        style="display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; background: #fff1f2; color: #f43f5e; border-radius: 8px;">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                                            stroke-linejoin="round">
                                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                            <circle cx="12" cy="10" r="3"></circle>
                                        </svg>
                                    </span>
                                    My Delivery Address
                                </label>
                                <?php if (isUserLoggedIn()): ?>
                                    <div style="display: flex; gap: 8px;">
                                        <a href="<?php echo $basePath; ?>/profile?edit=true" class="edit-location-btn"
                                            style="font-size: 13px; color: #f43f5e; font-weight: 700; text-decoration: none; padding: 6px 14px; background: #fff1f2; border: 1px solid #ffe4e6; border-radius: 10px; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); display: flex; align-items: center; gap: 6px;"
                                            onmouseover="this.style.background='#ffe4e6'; this.style.transform='translateY(-1px)';"
                                            onmouseout="this.style.background='#fff1f2'; this.style.transform='none';">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                                                stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                stroke-linejoin="round">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                            </svg>
                                            Edit
                                        </a>
                                        <!-- Hidden inputs for location tracking -->
                                            <input type="hidden" id="location_lat"
                                                value="<?php echo e($user['location_lat'] ?? ''); ?>">
                                            <input type="hidden" id="location_lng"
                                                value="<?php echo e($user['location_lng'] ?? ''); ?>">
                                            <input type="hidden" id="street_location"
                                                value="<?php echo e($user['street_location'] ?? ''); ?>">
                                            <input type="hidden" id="delivery_location"
                                                value="<?php echo e($user['delivery_location'] ?? ''); ?>">
                                        </div>
                                <?php endif; ?>
                            </div>

                            <div id="deliveryAddressContainer">
                                <?php if (isUserLoggedIn() && (!empty($user['delivery_location']) || !empty($user['street_location']))): ?>
                                        <div style="background: white; padding: 16px 20px; border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02), 0 2px 4px -1px rgba(0, 0, 0, 0.02); transition: all 0.3s ease; position: relative; overflow: hidden;"
                                            onmouseover="this.style.borderColor='#cbd5e1'; this.style.boxShadow='0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.02)';"
                                            onmouseout="this.style.borderColor='#e2e8f0'; this.style.boxShadow='0 4px 6px -1px rgba(0, 0, 0, 0.02), 0 2px 4px -1px rgba(0, 0, 0, 0.02)';">
                                            <div
                                                style="position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: linear-gradient(to bottom, #f43f5e, #fb7185);">
                                            </div>
                                            <?php if (!empty($user['delivery_location'])): ?>
                                                    <p
                                                        style="margin: 0 0 6px 0; font-weight: 700; color: #0f172a; font-size: 16px; font-family: 'Plus Jakarta Sans', sans-serif;">
                                                        <?php echo htmlspecialchars($user['delivery_location']); ?>
                                                    </p>
                                            <?php endif; ?>
                                            <?php if (!empty($user['street_location'])): ?>
                                                    <p class="delivery-address-text"
                                                        style="margin: 0; color: #64748b; font-size: 15px; display: block;">
                                                        <?php echo htmlspecialchars($user['street_location']); ?>
                                                    </p>
                                            <?php endif; ?>
                                        </div>
                                <?php else: ?>
                                        <div style="background: #f8fafc; padding: 24px; border: 2px dashed #cbd5e1; border-radius: 16px; text-align: center; transition: all 0.2s;"
                                            onmouseover="this.style.borderColor='#94a3b8'; this.style.background='#f1f5f9';"
                                            onmouseout="this.style.borderColor='#cbd5e1'; this.style.background='#f8fafc';">
                                            <div
                                                style="width: 48px; height: 48px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 12px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#94a3b8"
                                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                                    <circle cx="12" cy="10" r="3"></circle>
                                                </svg>
                                            </div>
                                            <p style="margin: 0 0 12px 0; color: #64748b; font-size: 14px; font-weight: 500;">No
                                                delivery address provided.</p>
                                            <?php if (isUserLoggedIn()): ?>
                                                    <a href="<?php echo $basePath; ?>/profile"
                                                        style="color: white; background: #f43f5e; text-decoration: none; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 12px; box-shadow: 0 4px 12px rgba(244, 63, 94, 0.25); transition: all 0.2s;"
                                                        onmouseover="this.style.transform='translateY(-2px)';"
                                                        onmouseout="this.style.transform='none';">
                                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                                                            stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                                            stroke-linejoin="round">
                                                            <line x1="12" y1="5" x2="12" y2="19"></line>
                                                            <line x1="5" y1="12" x2="19" y2="12"></line>
                                                        </svg>
                                                        Add Address
                                                    </a>
                                            <?php else: ?>
                                                    <p style="margin: 0; color: #f43f5e; font-size: 14px; font-weight: 600;">Please log
                                                        in
                                                        to add an address.</p>
                                            <?php endif; ?>
                                        </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- ============================================================ -->
                        <!-- FRIEND / FAMILY DELIVERY OPTION                              -->
                        <!-- ============================================================ -->
                        <div class="friend-order-toggle-wrapper" style="margin-bottom: 16px; margin-top: 4px;">
                            <label class="friend-order-checkbox-label" for="orderForFriendCheckbox">
                                <div class="friend-checkbox-visual" id="friendCheckboxVisual">
                                    <svg id="friendCheckIcon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="opacity:0;transform:scale(0);transition:all 0.2s cubic-bezier(0.34,1.56,0.64,1);">
                                        <polyline points="20 6 9 17 4 12"></polyline>
                                    </svg>
                                </div>
                                <input type="checkbox" id="orderForFriendCheckbox" style="position:absolute;opacity:0;pointer-events:none;">
                                <div class="friend-checkbox-text">
                                    <span class="friend-checkbox-title">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#f43f5e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                            <circle cx="9" cy="7" r="4"></circle>
                                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                        </svg>
                                        Ordering for a family member or friend?
                                    </span>
                                    <span class="friend-checkbox-sub">Send food to a different location. Delivery fee runs separately.</span>
                                </div>
                            </label>
                        </div>

                        <!-- Friend/Family Delivery Location Panel (hidden by default) -->
                        <div id="friendDeliveryPanel" style="display:none; margin-bottom: 20px;">
                            <div class="friend-delivery-card">
                                <div class="friend-delivery-header">
                                    <span class="friend-delivery-header-icon">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                            <circle cx="12" cy="10" r="3"></circle>
                                        </svg>
                                    </span>
                                    <span>Location for your demanded order</span>
                                </div>

                                <div style="padding: 14px 18px; display: flex; flex-direction: column; gap: 14px;">

                                    <!-- Delivery Location (Dropdown) -->
                                    <div>
                                        <label class="friend-field-label">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                            Delivery Location
                                        </label>
                                        <select id="friendDeliveryLocationSelect" class="friend-select-input" onchange="onFriendDeliveryLocationChange()">
                                            <option value="" disabled selected>Select Area</option>
                                            <option value="Bharatpur - 11">Bharatpur - 11</option>
                                            <option value="Bharatpur - 10">Bharatpur - 10</option>
                                            <option value="Bharatpur - 12">Bharatpur - 12</option>
                                            <option value="Bharatpur - 9">Bharatpur - 9</option>
                                            <option value="Rampur">Rampur</option>
                                            <option value="Sauraha">Sauraha</option>
                                            <option value="Other">Other</option>
                                        </select>
                                        <!-- Manual input shown when "Other" is selected -->
                                        <div id="friendManualLocationWrap" style="display:none; margin-top:8px;">
                                            <input type="text" id="friendManualLocation"
                                                placeholder="Type the area name"
                                                class="friend-location-input"
                                                style="padding-left:14px;">
                                        </div>
                                    </div>

                                    <!-- Street Location / Coordinates -->
                                    <div>
                                        <label class="friend-field-label">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
                                            GPS Coordinates / Map Link
                                        </label>
                                        <div style="display:flex; gap:8px; align-items:center; position:relative;">
                                            <input type="text" id="friendStreetLocationText"
                                                placeholder="Please PASTE your Google Maps link here..."
                                                class="friend-location-input"
                                                style="flex:1; padding-left:36px; padding-right:36px;"
                                                autocomplete="off"
                                                onkeydown="return event.ctrlKey || event.metaKey ? true : false;"
                                                oninput="document.getElementById('friendClearBtn').style.display = this.value ? 'flex' : 'none';">
                                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="position:absolute; left:14px; pointer-events:none;">
                                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                            </svg>
                                            <div id="friendClearBtn" style="position:absolute; right:10px; display:none; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:#f1f5f9; color:#64748b; cursor:pointer;" onclick="document.getElementById('friendStreetLocationText').value=''; this.style.display='none'; document.getElementById('friendFeeResult')?.remove(); hideFriendResult();" title="Clear link">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                            </div>
                                        </div>
                                        <div class="friend-input-notice">
                                            <span>Paste here the longitude and latitude for delivering</span>
                                            <a href="javascript:void(0)" class="see-instruction-link" onclick="openInstructionModal()">
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                                                See instruction
                                            </a>
                                        </div>
                                    </div>

                                    <!-- Calculate button -->
                                    <button type="button" class="friend-geocode-btn" id="friendCalcFeeBtn" onclick="calculateFriendFeeFromForm()" style="margin-top:2px;">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                        Calculate Delivery Fee
                                    </button>

                                </div>



                                <!-- Calculating spinner -->
                                <div id="friendFeeSpinner" style="display:none; text-align:center; padding:16px 0;">
                                    <div class="friend-spinner"></div>
                                    <p style="color:#64748b;font-size:13px;margin-top:8px;">Calculating delivery fee…</p>
                                </div>

                                <!-- Error -->
                                <div id="friendFeeError" style="display:none;" class="friend-fee-error"></div>
                            </div>
                        </div>
                        <!-- END FRIEND DELIVERY PANEL -->


                        <h2 class="section-title"
                            style="margin-top: 24px; border-top: 2px solid #f8fafc; padding-top: 24px;">Your Order</h2>
                        <div class="order-items-wrapper">
                            <div id="orderItemsList" class="order-items-list">
                                <!-- Items will be populated by JavaScript -->
                            </div>
                            <div id="basketScrollIndicator" class="scroll-indicator" style="display: none;">
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <path d="M7 10L12 15L17 10" stroke="currentColor" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <span>Scroll for more items</span>
                            </div>
                        </div>
                        <div id="emptyCartMessage" class="empty-cart-message" style="display: none;">
                            <div class="empty-cart-icon">
                                <svg width="64" height="64" viewBox="0 0 64 64" fill="none"
                                    xmlns="http://www.w3.org/2000/svg">
                                    <circle cx="32" cy="32" r="30" stroke="#d0d0d0" stroke-width="2"
                                        stroke-dasharray="4 4" opacity="0.5" />
                                    <path d="M32 20V32M32 32L38 38M32 32L26 38" stroke="#d0d0d0" stroke-width="2"
                                        stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </div>
                            <h3 class="empty-cart-title">Your basket is empty</h3>
                            <p class="empty-cart-text">Start adding delicious items from our menu</p>
                            <a href="<?php echo $basePath; ?>/menu" class="empty-cart-button">Browse Menu</a>
                        </div>

                        <!-- Additional Note -->
                        <div class="order-note-section"
                            style="margin-top: 20px; border-top: 1px solid #eee; padding-top: 20px;">
                            <label for="orderNote"
                                style="display: block; font-weight: 700; font-size: 14px; margin-bottom: 10px; color: #1a1a1a; display: flex; align-items: center; gap: 8px;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                </svg>
                                Additional Note (Optional)
                            </label>
                            <textarea id="orderNote" placeholder="e.g. Less spicy, Extra achar, No onions..."
                                style="width: 100%; padding: 15px; border: 2px solid #eee; border-radius: 12px; font-family: inherit; font-size: 14px; resize: vertical; min-height: 80px; transition: border-color 0.3s; background: #fafafa;"></textarea>
                        </div>


                    </div>






                    <!-- Order Summary -->
                    <div class="order-summary">
                        <div class="summary-row">
                            <span>Subtotal:</span>
                            <span class="price" id="orderSubtotal">Rs. 0.00</span>
                        </div>
                        <div class="summary-row" id="distanceRow" style="display: none;">
                            <span>Distance:</span>
                            <span class="price" id="orderDistance">0.00 km</span>
                        </div>
                        <div class="summary-row" id="deliveryFeeRow" style="display: none;">
                            <span>Delivery Fee:</span>
                            <span class="price" id="orderDeliveryFeeText">Rs. 0.00</span>
                        </div>
                        <div class="summary-row total-row"
                            style="border-top: 1px solid #eee; padding-top: 12px; margin-top: 12px;">
                            <span>Order Total:</span>
                            <span class="price" id="orderTotal">Rs. 0.00</span>
                        </div>
                    </div>
                    <!-- Order Button -->
                    <button class="order-now-btn" id="orderNowBtn" style="margin-top: 10px;">
                        <span id="orderBtnText">ORDER NOW</span>
                    </button>

                    <!-- Leaflet OpenStreetMap Location -->
                    <div class="location-map-section">
                        <h3 class="map-title">Our Location</h3>
                        <div class="map-container">
                            <iframe
                                src="https://www.google.com/maps/embed?pb=!1m14!1m12!1m3!1d14138.0!2d84.449315!3d27.691582!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!5e0!3m2!1sen!2snp!4v1741114244000!5m2!1sen!2snp"
                                width="100%" height="300px" style="border:0; border-radius: 12px; display: block;"
                                allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade">
                            </iframe>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Payment Method Selection Modal -->
        <div class="payment-method-modal" id="paymentMethodModal">
            <div class="payment-modal-backdrop"></div>
            <div class="payment-modal-content">
                <!-- Futuristic Background Elements -->
                <div class="modal-glow-1"></div>
                <div class="modal-glow-2"></div>

                <div class="modal-header">
                    <div class="header-icon-seal">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M12 2L3 7v9c0 5 9 6 9 6s9-1 9-6V7l-9-5z" />
                            <path d="M12 22s-9-1-9-6V7l9-5 9 5v9c0 5-9 6-9 6z" />
                        </svg>
                    </div>
                    <h3>Payment Method</h3>
                    <p>Select your preferred secure payment gateway</p>
                </div>

                <div class="payment-options">
                    <!-- Cash On Delivery -->
                    <label class="payment-option">
                        <input type="radio" name="modal_payment_method" value="cod" checked>
                        <div class="payment-card cod-card">
                            <div class="payment-icon-wrapper">
                                <div class="icon-pulse"></div>
                                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2">
                                    <rect x="2" y="6" width="20" height="12" rx="2" />
                                    <circle cx="12" cy="12" r="2" />
                                </svg>
                            </div>
                            <div class="payment-details">
                                <span class="method-title">Cash on Delivery</span>
                                <span class="method-desc">Pay upon successful delivery</span>
                            </div>
                            <div class="selection-check">
                                <div class="check-dot"></div>
                            </div>
                        </div>
                    </label>

                    <!-- Esewa -->
                    <label class="payment-option">
                        <input type="radio" name="modal_payment_method" value="esewa">
                        <div class="payment-card esewa-card">
                            <div class="payment-icon-wrapper" style="overflow: hidden;">
                                <div class="icon-pulse"></div>
                                <img src="<?php echo $basePath; ?>/assets/esewalogo.jpg" alt="Esewa"
                                    style="width: 140%; height: 140%; object-fit: cover; position: relative; z-index: 1; transform: scale(1.1);">
                            </div>
                            <div class="payment-details">
                                <div class="title-with-badge">
                                    <span class="method-title">Esewa Wallet</span>
                                    <span class="next-gen-badge">NEXT GEN</span>
                                </div>
                                <span class="method-desc">All, Always, Together</span>
                            </div>
                            <div class="selection-check">
                                <div class="check-dot"></div>
                            </div>
                        </div>
                    </label>
                </div>

                <!-- PayPal Notice Banner -->
                <div class="paypal-notice-banner">
                    <div class="paypal-notice-inner">
                        <div class="paypal-notice-icon">
                            <img src="<?php echo $basePath; ?>/assets/paypal.png" alt="PayPal" class="paypal-plate-icon">
                        </div>
                        <div class="paypal-notice-text">
                            <p class="paypal-notice-slogan">🌏 Your family's in Nepal?</p>
                            <p class="paypal-notice-sub">Surprise them with a meal. <strong>Pay via PayPal</strong> from anywhere in the world!</p>
                            <a href="https://wa.me/9779749705085" target="_blank" class="paypal-contact-link" id="paypalContactBtn">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.18 2 2 0 0 1 3.59 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.56a16 16 0 0 0 6 6l.92-.92a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                Contact us to order
                            </a>
                        </div>
                    </div>
                </div>

                <style>
                    @keyframes paypalBannerPulse {
                        0%, 100% {
                            border-color: #c7d7fc;
                            box-shadow: 0 0 0 0 rgba(0, 144, 222, 0);
                        }
                        50% {
                            border-color: #009cde;
                            box-shadow: 0 0 0 4px rgba(0, 144, 222, 0.12);
                        }
                    }
                    @keyframes paypalShimmer {
                        0% { background-position: -200% center; }
                        100% { background-position: 200% center; }
                    }
                    @keyframes paypalSloganPop {
                        0%, 100% { opacity: 1; transform: scale(1); }
                        50% { opacity: 0.85; transform: scale(1.02); }
                    }
                    .paypal-notice-banner {
                        margin: 18px 0 0 0;
                        border-radius: 18px;
                        background: linear-gradient(135deg, #eef4ff 0%, #e0ecfd 100%);
                        border: 1.5px solid #c7d7fc;
                        overflow: hidden;
                        position: relative;
                        animation: paypalBannerPulse 2.5s ease-in-out infinite;
                    }
                    .paypal-notice-banner::before {
                        content: '';
                        position: absolute;
                        top: 0; left: 0;
                        width: 4px; height: 100%;
                        background: linear-gradient(to bottom, #003087, #009cde);
                        border-radius: 18px 0 0 18px;
                    }
                    .paypal-notice-inner {
                        display: flex;
                        align-items: center;
                        gap: 12px;
                        padding: 14px 16px 14px 20px;
                    }
                    .paypal-notice-icon {
                        flex-shrink: 0;
                        width: 52px;
                        height: 36px;
                        border-radius: 10px;
                        background: #fff;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        box-shadow: 0 2px 8px rgba(0, 48, 135, 0.15);
                        border: 1px solid #dde8fc;
                        overflow: hidden;
                        padding: 4px;
                    }
                    .paypal-plate-icon {
                        width: 100%;
                        height: 100%;
                        object-fit: contain;
                    }
                    .paypal-notice-text {
                        flex: 1;
                        min-width: 0;
                    }
                    .paypal-notice-slogan {
                        margin: 0 0 3px 0;
                        font-family: 'Plus Jakarta Sans', sans-serif;
                        font-size: 13.5px;
                        font-weight: 800;
                        color: #002d72;
                        letter-spacing: -0.3px;
                        line-height: 1.35;
                        animation: paypalSloganPop 2.5s ease-in-out infinite;
                    }
                    .paypal-notice-sub {
                        margin: 0 0 9px 0;
                        font-family: 'Inter', sans-serif;
                        font-size: 11.5px;
                        color: #4a5568;
                        line-height: 1.55;
                        letter-spacing: 0.1px;
                    }
                    .paypal-notice-sub strong {
                        color: #003087;
                        font-weight: 700;
                        font-style: italic;
                    }
                    .paypal-contact-link {
                        display: inline-flex;
                        align-items: center;
                        gap: 5px;
                        font-family: 'Inter', sans-serif;
                        font-size: 11.5px;
                        font-weight: 700;
                        color: #fff;
                        background: linear-gradient(
                            90deg,
                            #003087 0%,
                            #009cde 40%,
                            #00c2ff 60%,
                            #003087 100%
                        );
                        background-size: 200% auto;
                        padding: 6px 13px;
                        border-radius: 8px;
                        text-decoration: none;
                        transition: box-shadow 0.2s ease, transform 0.2s ease;
                        box-shadow: 0 2px 8px rgba(0, 48, 135, 0.3);
                        white-space: nowrap;
                        animation: paypalShimmer 2s linear infinite;
                    }
                    .paypal-contact-link:hover {
                        transform: translateY(-1px);
                        box-shadow: 0 4px 14px rgba(0, 48, 135, 0.45);
                    }
                    .paypal-contact-link svg {
                        flex-shrink: 0;
                    }
                    @media (max-width: 380px) {
                        .paypal-notice-slogan { font-size: 12.5px; }
                        .paypal-notice-sub { font-size: 11px; }
                        .paypal-notice-icon { width: 44px; height: 30px; }
                    }
                </style>

                <div class="modal-footer">
                    <button id="confirmPaymentBtn" class="confirm-order-btn">
                        <span class="btn-shine"></span>
                        <span class="btn-text">Confirm & Place Order</span>
                        <svg class="btn-arrow" width="20" height="20" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2.5">
                            <path d="M5 12h14M12 5l7 7-7 7" />
                        </svg>
                    </button>

                    <button id="closePaymentModalBtn" class="modal-go-back">
                        Go Back
                    </button>
                </div>
            </div>

            <style>
                #paymentMethodModal {
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    z-index: 10000;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    perspective: 1000px;
                    opacity: 0;
                    visibility: hidden;
                    pointer-events: none;
                    transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
                    /* Allow scrolling if modal is taller than viewport */
                    overflow-y: auto;
                    padding: 20px 0;
                    box-sizing: border-box;
                }

                #paymentMethodModal.active {
                    opacity: 1;
                    visibility: visible;
                    pointer-events: auto;
                }

                .payment-modal-backdrop {
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(15, 23, 42, 0.6);
                    backdrop-filter: blur(12px) saturate(180%);
                    -webkit-backdrop-filter: blur(12px) saturate(180%);
                    transition: all 0.4s ease;
                }

                .payment-modal-content {
                    position: relative;
                    background: #ffffff;
                    width: 92%;
                    max-width: 440px;
                    padding: 40px 30px;
                    border-radius: 32px;
                    box-shadow:
                        0 25px 50px -12px rgba(0, 0, 0, 0.25),
                        inset 0 0 0 1px rgba(255, 255, 255, 0.1);
                    transform: scale(0.9) translateY(20px);
                    transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
                    overflow: visible; /* allow header icon to breathe */
                    flex-shrink: 0;
                    margin: auto;
                }

                #paymentMethodModal.active .payment-modal-content {
                    transform: scale(1) translateY(0);
                }

                /* Restaurant Closed Modal - Premium Animation */
                .restaurant-closed-modal {
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    z-index: 9999;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    opacity: 0;
                    visibility: hidden;
                    transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
                }

                .restaurant-closed-modal.active {
                    opacity: 1;
                    visibility: visible;
                }

                .restaurant-closed-backdrop {
                    position: absolute;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(15, 23, 42, 0.6);
                    backdrop-filter: blur(12px);
                    -webkit-backdrop-filter: blur(12px);
                    opacity: 0;
                    transition: opacity 0.4s ease;
                }

                .restaurant-closed-modal.active .restaurant-closed-backdrop {
                    opacity: 1;
                }

                .restaurant-closed-content {
                    background: white;
                    width: 90%;
                    max-width: 400px;
                    padding: 30px 24px;
                    border-radius: 32px;
                    box-shadow: 0 40px 80px -20px rgba(0, 0, 0, 0.3);
                    text-align: center;
                    position: relative;
                    transform: scale(0.9) translateY(20px);
                    opacity: 0;
                    transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
                    z-index: 2;
                    overflow: hidden;
                    animation: futuristicSlideIn 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
                    transform-style: preserve-3d;
                }

                .restaurant-closed-modal.active .restaurant-closed-content {
                    transform: scale(1) translateY(0);
                    opacity: 1;
                }

                .restaurant-closed-icon {
                    margin-bottom: 16px;
                    display: flex;
                    justify-content: center;
                }

                .restaurant-closed-icon svg circle {
                    stroke: #FF5252;
                }

                .restaurant-closed-icon svg path {
                    stroke: #FF5252;
                }

                .restaurant-closed-icon svg g.clock-hands path {
                    stroke: #FF5252;
                }

                .restaurant-closed-icon svg circle[fill="#FFA53B"] {
                    fill: #FF5252;
                    opacity: 0.1;
                }

                /* Real-time Clock Hands */
                .modal-hour-hand,
                .modal-minute-hand,
                .modal-second-hand {
                    transform-origin: 40px 40px;
                    transition: transform 0.3s cubic-bezier(0.4, 2.08, 0.55, 0.44);
                    /* Bouncy ticking */
                }

                .modal-second-hand {
                    transition: transform 0.1s linear;
                    /* Smooth second hand */
                }

                .restaurant-closed-title {
                    font-size: 24px;
                    font-weight: 800;
                    color: #1e293b;
                    margin-bottom: 8px;
                    line-height: 1.3;
                }

                .restaurant-closed-message {
                    font-size: 16px;
                    color: #64748b;
                    line-height: 1.6;
                    margin-bottom: 20px;
                }

                .restaurant-closed-btn {
                    background: linear-gradient(135deg, #FF5252 0%, #ff1744 100%);
                    color: white;
                    border: none;
                    padding: 16px 32px;
                    border-radius: 16px;
                    font-size: 16px;
                    font-weight: 700;
                    width: 100%;
                    cursor: pointer;
                    transition: all 0.3s ease;
                    box-shadow: 0 10px 20px -5px rgba(255, 82, 82, 0.4);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 10px;
                    font-family: 'Plus Jakarta Sans', sans-serif;
                }

                /* Clock Styles inside Modal */
                .restaurant-closed-clock {
                    background: #fffbf0;
                    border: 1px solid #ffeeba;
                    border-radius: 20px;
                    padding: 20px;
                    margin: 24px 0;
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    gap: 8px;
                }

                .restaurant-closed-clock .clock-label {
                    font-family: 'Inter', sans-serif;
                    font-size: 12px;
                    font-weight: 600;
                    color: #94a3b8;
                    text-transform: uppercase;
                    letter-spacing: 1px;
                }

                .restaurant-closed-clock .clock-display {
                    font-family: 'Plus Jakarta Sans', sans-serif;
                    /* The fix */
                    font-size: 32px;
                    font-weight: 800;
                    /* Bold */
                    color: #1e293b;
                    font-variant-numeric: tabular-nums;
                    letter-spacing: -1px;
                }

                .restaurant-closed-clock .clock-period {
                    font-size: 16px;
                    font-weight: 700;
                    color: #64748b;
                    margin-left: 4px;
                }

                .restaurant-closed-btn:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 15px 30px -5px rgba(255, 82, 82, 0.5);
                }

                .restaurant-closed-btn:active {
                    transform: translateY(0);
                }

                @keyframes futuristicSlideIn {
                    0% {
                        opacity: 0;
                        transform: translateY(40px) scale(0.92) rotateX(-10deg);
                    }

                    100% {
                        opacity: 1;
                        transform: translateY(0) scale(1) rotateX(0deg);
                    }
                }

                /* Contain glows inside the card without clipping header icon */
                .payment-modal-content .modal-glow-1,
                .payment-modal-content .modal-glow-2 {
                    border-radius: 32px;
                    clip-path: inset(0 round 32px);
                }

                .modal-header {
                    text-align: center;
                    margin-bottom: 35px;
                    position: relative;
                    z-index: 1;
                }

                .modal-header h3 {
                    font-family: 'Plus Jakarta Sans', sans-serif;
                    font-size: 28px;
                    font-weight: 800;
                    color: #1e293b;
                    margin: 0;
                    letter-spacing: -0.5px;
                }

                @keyframes iconFloat {
                    0%   { transform: rotate(-5deg) translateY(0px);   box-shadow: 0 10px 20px rgba(0,0,0,0.15); }
                    50%  { transform: rotate(3deg)  translateY(-8px);  box-shadow: 0 18px 30px rgba(0,0,0,0.20); }
                    100% { transform: rotate(-5deg) translateY(0px);   box-shadow: 0 10px 20px rgba(0,0,0,0.15); }
                }

                .header-icon-seal {
                    width: 64px;
                    height: 64px;
                    background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
                    color: white;
                    border-radius: 20px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    margin: 0 auto 20px;
                    box-shadow: 0 10px 20px rgba(0, 0, 0, 0.15);
                    animation: iconFloat 3s ease-in-out infinite;
                }

                .modal-header p {
                    font-family: 'Plus Jakarta Sans', sans-serif;
                    font-size: 15px;
                    color: #64748b;
                    margin-top: 10px;
                    line-height: 1.5;
                }

                .modal-footer {
                    display: flex;
                    flex-direction: column;
                    gap: 12px;
                    margin-top: 10px;
                }

                .confirm-order-btn {
                    background: linear-gradient(135deg, #FF5252 0%, #ff1744 100%);
                    color: white;
                    border: none;
                    padding: 18px 32px;
                    border-radius: 18px;
                    font-size: 17px;
                    font-weight: 700;
                    width: 100%;
                    cursor: pointer;
                    transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
                    box-shadow: 0 10px 25px -5px rgba(255, 82, 82, 0.4);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    gap: 12px;
                    font-family: 'Plus Jakarta Sans', sans-serif;
                    position: relative;
                    overflow: hidden;
                }

                .confirm-order-btn:hover {
                    transform: translateY(-3px);
                    box-shadow: 0 15px 35px -5px rgba(255, 82, 82, 0.5);
                }

                .confirm-order-btn:active {
                    transform: translateY(0);
                }

                .btn-arrow {
                    transition: transform 0.3s ease;
                }

                .confirm-order-btn:hover .btn-arrow {
                    transform: translateX(5px);
                }

                .modal-go-back {
                    background: #1e293b;
                    border: none;
                    color: #e2e8f0;
                    font-size: 15px;
                    font-weight: 600;
                    padding: 13px;
                    cursor: pointer;
                    transition: all 0.2s ease;
                    font-family: 'Plus Jakarta Sans', sans-serif;
                    text-decoration: none;
                    text-align: center;
                    border-radius: 14px;
                    letter-spacing: 0.1px;
                }

                .modal-go-back:hover {
                    background: #0f172a;
                    color: #fff;
                }

                .payment-options {
                    display: flex;
                    flex-direction: column;
                    gap: 16px;
                    margin-bottom: 35px;
                    position: relative;
                    z-index: 1;
                }

                .payment-option {
                    position: relative;
                    cursor: pointer;
                    display: block;
                }

                .payment-option input {
                    position: absolute;
                    opacity: 0;
                }

                .payment-card {
                    background: white;
                    border: 2px solid #f1f5f9;
                    border-radius: 24px;
                    padding: 20px;
                    display: flex;
                    align-items: center;
                    gap: 18px;
                    transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
                    position: relative;
                }

                .payment-icon-wrapper {
                    width: 50px;
                    height: 50px;
                    background: #f8fafc;
                    border-radius: 16px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    position: relative;
                    transition: all 0.4s ease;
                }

                .icon-pulse {
                    position: absolute;
                    width: 100%;
                    height: 100%;
                    background: currentColor;
                    border-radius: inherit;
                    opacity: 0;
                    transform: scale(1);
                }

                .payment-card svg {
                    position: relative;
                    z-index: 1;
                    transition: all 0.4s ease;
                }

                .cod-card {
                    color: #FF5252;
                }

                .esewa-card {
                    color: #60bb46;
                }

                .payment-details {
                    flex: 1;
                    display: flex;
                    flex-direction: column;
                }

                .method-title {
                    font-family: 'Plus Jakarta Sans', sans-serif;
                    font-weight: 700;
                    font-size: 17px;
                    color: #1e293b;
                }

                .method-desc {
                    font-family: 'Inter', sans-serif;
                    font-size: 13px;
                    color: #64748b;
                    margin-top: 2px;
                }

                .title-with-badge {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    flex-wrap: nowrap;
                    white-space: nowrap;
                }

                .next-gen-badge {
                    font-family: 'Inter', sans-serif;
                    font-size: 9px;
                    background: rgba(96, 187, 70, 0.1);
                    color: #60bb46;
                    padding: 3px 8px;
                    border-radius: 6px;
                    font-weight: 800;
                    border: 1px solid rgba(96, 187, 70, 0.2);
                    flex-shrink: 0;
                    white-space: nowrap;
                }

                .selection-check {
                    width: 26px;
                    height: 26px;
                    border: 2px solid #e2e8f0;
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    transition: all 0.4s ease;
                }

                .check-dot {
                    width: 12px;
                    height: 12px;
                    background: transparent;
                    border-radius: 50%;
                    transform: scale(0);
                    transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
                }

                /* Selection States */
                .payment-option input:checked+.payment-card {
                    border-color: #FF5252;
                    background: #fffafa;
                    box-shadow: 0 15px 30px rgba(255, 82, 82, 0.08);
                    transform: translateY(-3px) scale(1.02);
                }

                .payment-option input:checked+.payment-card .payment-icon-wrapper {
                    background: #FF5252;
                    color: white;
                    box-shadow: 0 8px 15px rgba(255, 82, 82, 0.2);
                }

                .payment-option input:checked+.payment-card .selection-check {
                    border-color: #FF5252;
                }

                .payment-option input:checked+.payment-card .check-dot {
                    background: #FF5252;
                    transform: scale(1);
                }

                .payment-option:hover .payment-card {
                    border-color: #1a1a1a;
                }
            </style>
        </div>

        <!-- Restaurant Closed Warning Modal -->
        <div class="restaurant-closed-modal" id="restaurantClosedModal">
            <div class="restaurant-closed-backdrop"></div>
            <div class="restaurant-closed-content">
                <div class="restaurant-closed-icon">
                    <svg width="80" height="80" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="40" cy="40" r="36" fill="#FF5252" opacity="0.1" />
                        <!-- Dial Numbers -->
                        <g fill="#FF5252"
                            style="font-family: 'Plus Jakarta Sans', sans-serif; font-size: 8px; font-weight: 800; opacity: 0.6;">
                            <text x="40" y="16" text-anchor="middle">12</text>
                            <text x="64" y="43" text-anchor="middle">3</text>
                            <text x="40" y="68" text-anchor="middle">6</text>
                            <text x="16" y="43" text-anchor="middle">9</text>
                        </g>

                        <!-- Real-time Clock Hands -->
                        <g>
                            <!-- Hour Hand -->
                            <path id="modalHourHand" class="modal-hour-hand" d="M40 40L40 26" stroke="#FF5252"
                                stroke-width="4" stroke-linecap="round" />
                            <!-- Minute Hand -->
                            <path id="modalMinuteHand" class="modal-minute-hand" d="M40 40L40 18" stroke="#FF5252"
                                stroke-width="3" stroke-linecap="round" />
                            <!-- Second Hand -->
                            <path id="modalSecondHand" class="modal-second-hand" d="M40 40L40 15" stroke="#FF5252"
                                stroke-width="1.5" stroke-linecap="round" />
                            <!-- Center cap -->
                            <circle cx="40" cy="40" r="2.5" fill="#FF5252" />
                        </g>
                        <!-- Outer decorative dial -->
                        <circle cx="40" cy="40" r="30" stroke="#FF5252" stroke-width="2" stroke-dasharray="2 6"
                            opacity="0.2" />
                    </svg>
                </div>
                <div class="restaurant-closed-clock" style="margin-bottom: 12px;">
                    <div class="clock-label">Current Time (Nepal)</div>
                    <div class="clock-display" id="restaurantClosedClock">
                        <span class="clock-hours">00</span><span class="clock-separator">:</span><span
                            class="clock-minutes">00</span><span class="clock-separator">:</span><span
                            class="clock-seconds">00</span> <span class="clock-period">AM</span>
                    </div>
                </div>
                <h2 class="restaurant-closed-title">Sorry, We're Currently Closed</h2>
                <!-- Countdown to Opening -->
                <div class="opening-countdown-wrapper" id="modalOpeningCountdown"
                    style="margin: 12px 0; background: rgba(255, 82, 82, 0.05); padding: 12px; border-radius: 20px; border: 1px dashed rgba(255, 82, 82, 0.2);">
                    <div
                        style="font-family: 'Inter', sans-serif; font-size: 13px; font-weight: 700; color: #64748b; letter-spacing: 3px; text-transform: uppercase; margin-bottom: 8px;">
                        We will open in</div>
                    <div id="modalCountdownValue"
                        style="font-family: 'Plus Jakarta Sans', sans-serif; font-size: 32px; font-weight: 800; color: #FF5252; letter-spacing: 2px; font-variant-numeric: tabular-nums;">
                        00:00:00</div>
                </div>
                <p class="restaurant-closed-message" id="restaurantClosedMessage">We'd love to serve you! Please check
                    back during our opening hours to place your order.</p>
                <div class="restaurant-closed-slogan" style="margin-bottom: 20px;">
                    <span
                        style="font-family: 'Inter', sans-serif; font-size: 18px; font-weight: 700; color: #1e293b; display: block; margin-bottom: 5px;">“भोलि
                        फेरि भेटौँला।” 😊</span>
                </div>
                <div class="restaurant-closed-actions">
                    <button class="restaurant-closed-btn" id="closeRestaurantClosedBtn">
                        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M9 1.5V4.5M9 13.5V16.5M16.5 9H13.5M4.5 9H1.5" stroke="currentColor"
                                stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span>Got It</span>
                    </button>
                </div>
            </div>
        </div> <!-- Empty Cart Warning Modal -->
        <div class="empty-cart-warning-modal" id="emptyCartWarningModal">
            <div class="empty-cart-warning-backdrop"></div>
            <div class="empty-cart-warning-content">
                <div class="empty-cart-warning-icon"> <svg width="90" height="90" viewBox="0 0 90 90" fill="none"
                        xmlns="http://www.w3.org/2000/svg">
                        <circle cx="45" cy="45" r="40" fill="#FFA53B" opacity="0.1" />
                        <path d="M30 35L35 40L60 15" stroke="#FFA53B" stroke-width="4" stroke-linecap="round"
                            stroke-linejoin="round" class="warning-icon-line" />
                        <path d="M45 25V45M45 45L55 55M45 45L35 55" stroke="#FFA53B" stroke-width="3"
                            stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M25 60L30 65L65 30" stroke="#FFA53B" stroke-width="4" stroke-linecap="round"
                            stroke-linejoin="round" class="warning-icon-line" />
                    </svg> </div>
                <h2 class="empty-cart-warning-title">Cart is Empty !</h2>
                <p class="empty-cart-warning-message">Please add to your cart first or choose dishes</p>
                <div class="empty-cart-warning-actions"> <a href="<?php echo $basePath; ?>/menu"
                        class="empty-cart-warning-btn"> <svg width="20" height="20" viewBox="0 0 20 20" fill="none"
                            xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M3.33334 3.33334H16.6667C17.5871 3.33334 18.3333 4.07953 18.3333 5.00001V15C18.3333 15.9205 17.5871 16.6667 16.6667 16.6667H3.33334C2.41286 16.6667 1.66667 15.9205 1.66667 15V5.00001C1.66667 4.07953 2.41286 3.33334 3.33334 3.33334Z"
                                stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                stroke-linejoin="round" />
                            <path d="M6.66667 7.5H13.3333" stroke="currentColor" stroke-width="1.5"
                                stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M6.66667 10.8333H13.3333" stroke="currentColor" stroke-width="1.5"
                                stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M6.66667 14.1667H10" stroke="currentColor" stroke-width="1.5"
                                stroke-linecap="round" stroke-linejoin="round" />
                        </svg> Browse Menu </a> </div>
            </div>
        </div> <!-- Order Success Popup Modal -->
        <div class="order-success-modal" id="orderSuccessModal">
            <div class="order-success-backdrop"></div>
            <div class="order-success-content">
                <div class="order-success-icon">
                    <div class="success-icon-wrapper">
                        <div class="success-circle-outer"></div>
                        <div class="success-circle-middle"></div>
                        <div class="success-circle-inner"></div> <svg class="success-checkmark" width="90" height="90"
                            viewBox="0 0 90 90" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="45" cy="45" r="40" fill="#4CAF50" opacity="0.15" />
                            <path d="M28 45L38 55L62 31" stroke="#4CAF50" stroke-width="5" stroke-linecap="round"
                                stroke-linejoin="round" class="checkmark-path" />
                        </svg>
                    </div>
                </div>
                <h2 class="order-success-title">Your Order is on Process !</h2>
                <p class="order-success-message">Track your order to see the process and order details</p>
                <div class="order-success-actions"> <a href="<?php echo $basePath; ?>/order-tracking.php"
                        class="order-success-btn"> <svg width="20" height="20" viewBox="0 0 20 20" fill="none"
                            xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M10 18.3333C14.6024 18.3333 18.3333 14.6024 18.3333 10C18.3333 5.39763 14.6024 1.66667 10 1.66667C5.39763 1.66667 1.66667 5.39763 1.66667 10C1.66667 14.6024 5.39763 18.3333 10 18.3333Z"
                                stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                stroke-linejoin="round" />
                            <path d="M10 6.66667V10L12.5 12.5" stroke="currentColor" stroke-width="1.5"
                                stroke-linecap="round" stroke-linejoin="round" />
                        </svg> Track Your Order </a> </div>
            </div>
        </div> <!-- Operating Hours Modal -->
        <div class="operating-hours-modal" id="operatingHoursModal">
            <div class="operating-hours-backdrop"></div>
            <div class="operating-hours-content"> <button class="operating-hours-close"
                    id="closeOperatingHoursBtn">&times;
                </button>
                <div class="operating-hours-header">
                    <h2 class="operating-hours-title">Delivery Hours</h2>
                </div>
                <div class="operating-hours-display">
                    <div class="hours-table-body" id="hoursTableBody">
                        <!-- Hours will be populated by JavaScript -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Instruction Image Modal -->
        <div class="instruction-modal" id="instructionImageModal">
            <div class="instruction-modal-backdrop" onclick="closeInstructionModal()"></div>
            <div class="instruction-modal-content">
                <div class="instruction-modal-body" style="text-align: center; padding: 5px;">
                    <img src="assets/instruction.jpeg" alt="Instruction" style="width: 92%; border-radius: 12px; display: inline-block; box-shadow: 0 4px 15px rgba(0,0,0,0.08);">
                </div>
                <div class="instruction-modal-footer">
                    <button class="instruction-bottom-close-btn" onclick="closeInstructionModal()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                        I Understand, Close
                    </button>
                </div>
            </div>
        </div>

        <style>
            /* Modern Operating Hours Modal Styles */
            .operating-hours-modal {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 10000;
                display: flex;
                justify-content: center;
                align-items: center;
                opacity: 0;
                visibility: hidden;
                transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            }

            .operating-hours-modal.active {
                opacity: 1;
                visibility: visible;
            }

            .operating-hours-modal.active .operating-hours-backdrop {
                opacity: 1;
            }

            .operating-hours-backdrop {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0, 0, 0, 0.4);
                backdrop-filter: blur(8px);
                -webkit-backdrop-filter: blur(8px);
                opacity: 0;
                transition: opacity 0.3s ease;
            }

            .operating-hours-content {
                position: relative;
                background: #ffffff;
                width: 90%;
                max-width: 400px;
                padding: 30px;
                border-radius: 24px;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
                transform: translateY(20px) scale(0.96);
                transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
                z-index: 1;
                text-align: center;
                border: 1px solid rgba(0, 0, 0, 0.05);
            }

            .operating-hours-modal.active .operating-hours-content {
                transform: translateY(0) scale(1);
            }

            .operating-hours-close {
                position: absolute;
                top: 15px;
                right: 15px;
                background: transparent;
                border: none;
                width: 32px;
                height: 32px;
                border-radius: 50%;
                font-size: 24px;
                line-height: 1;
                color: #94a3b8;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: all 0.2s ease;
            }

            .operating-hours-close:hover {
                background: #f1f5f9;
                color: #000;
                transform: rotate(90deg);
            }

            .operating-hours-header h2.operating-hours-title {
                font-family: 'Plus Jakarta Sans', sans-serif;
                font-size: 22px;
                font-weight: 800;
                color: #1e293b;
                margin: 0 0 20px 0;
                letter-spacing: -0.5px;
                text-align: left;
            }

            /* Inner Content Styling */
            .hours-simple-message {
                display: flex;
                align-items: center;
                gap: 16px;
                background: #f8fafc;
                padding: 20px;
                border-radius: 16px;
                border: 1px solid #e2e8f0;
                text-align: left;
            }

            .hours-icon {
                width: 48px;
                height: 48px;
                background: #fdf2f8;
                /* Soft Pink Bg */
                color: #db2777;
                /* Pink Icon */
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 24px;
                flex-shrink: 0;
            }

            .hours-text h3 {
                font-family: 'Plus Jakarta Sans', sans-serif;
                font-size: 13px;
                font-weight: 700;
                color: #64748b;
                margin: 0 0 4px 0;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            .hours-text .hours-time {
                font-family: 'Plus Jakarta Sans', sans-serif;
                font-size: 17px;
                font-weight: 800;
                color: #1e293b;
                margin: 0;
            }
        </style>

        <script> // ============================================================================
            // SERVICE TYPE CONFIGURATION
            // ============================================================================
            // Force delivery service only
            // ============================================================================

            // ALWAYS DEFAULT TO DELIVERY
            let currentService = 'delivery';

            // Ensure UI reflects delivery state
            const deliverySection = document.getElementById('deliverySection');
            if (deliverySection) deliverySection.style.display = 'block';

            const orderBtnText = document.getElementById('orderBtnText');

            if (orderBtnText) {
                // Only update button text if restaurant is open
                // We'll rely on updateStatus() to handle basic text, but ensure it starts correct
                orderBtnText.textContent = 'ORDER NOW';
            }

            // Update order summary after cart is rendered
            setTimeout(() => {
                if (typeof updateOrderSummary === 'function') updateOrderSummary();
            }

                , 100);

            // ============================================================================
            // CART MANAGEMENT
            // ============================================================================
            // Handles rendering cart items, updating quantities, and removing items
            // ============================================================================

            // Get cart data from PHP session
            const cart =
                <?php echo json_encode($_SESSION['cart'] ?? []); ?>
                ;

            /**
* Render cart items to the DOM
* Updates the order items list and shows/hides empty cart message
*/
            function renderCart() {
                const orderItemsList = document.getElementById('orderItemsList');
                const emptyCartMessage = document.getElementById('emptyCartMessage');
                const basketScrollIndicator = document.getElementById('basketScrollIndicator');

                if (cart.length === 0) {
                    orderItemsList.innerHTML = '';
                    emptyCartMessage.style.display = 'block';
                    if (basketScrollIndicator) basketScrollIndicator.style.display = 'none';
                    updateOrderSummary(); // Update summary to clear totals
                    return;
                }

                emptyCartMessage.style.display = 'none';

                orderItemsList.innerHTML = cart.map((item, index) => {

                    // Helper function to parse price (same logic as updateOrderSummary)
                    const parsePrice = (price) => {
                        if (typeof price === 'string') {
                            return parseFloat(price.replace(/Rs\./g, '').replace(/[^\d.]/g, '')) || 0;
                        }

                        return parseFloat(price) || 0;
                    }

                        ;

                    const itemTotal = (parsePrice(item.price) * item.quantity).toFixed(2);
                    const itemImage = item.image || 'assets/plate.png';

                    return ` <div class="order-item" data-index="${index}" > <div class="item-image-wrapper" > <img src="${itemImage}" alt="${item.name}" class="item-image" > </div> <div class="item-info" > <span class="item-name" >${item.name
                        }

                                </span> <span class="item-price" >Rs. ${itemTotal
                        }

                                </span> </div> <div class="item-controls" > <button class="qty-btn minus" data-index="${index}" data-action="decrease" >−</button> <span class="qty-value" >${item.quantity
                        }

                                </span> <button class="qty-btn plus" data-index="${index}" data-action="increase" >+</button> <button class="remove-btn" onclick="removeItem(${index})" aria-label="Remove item" > <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" > <path d="M3 6H5H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /> <path d="M8 6V4C8 3.46957 8.21071 2.96086 8.58579 2.58579C8.96086 2.21071 9.46957 2 10 2H14C14.5304 2 15.0391 2.21071 15.4142 2.58579C15.7893 2.96086 16 3.46957 16 4V6M19 6V20C19 20.5304 18.7893 21.0391 18.4142 21.4142C18.0391 21.7893 17.5304 22 17 22H7C6.46957 22 5.96086 21.7893 5.58579 21.4142C5.21071 21.0391 5 20.5304 5 20V6H19Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /> <path d="M10 11V17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /> <path d="M14 11V17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /> </svg> </button> </div> </div> `;
                }).join('');

                updateOrderSummary();

                // Show/hide scroll indicator
                setTimeout(() => {
                    checkBasketScrollIndicator();
                }

                    , 100);
            }

            // Check if scroll indicator should be shown for basket
            function checkBasketScrollIndicator() {
                const orderItemsList = document.getElementById('orderItemsList');
                const basketScrollIndicator = document.getElementById('basketScrollIndicator');

                if (!orderItemsList || !basketScrollIndicator) return;

                // Check if content is scrollable
                const isScrollable = orderItemsList.scrollHeight > orderItemsList.clientHeight;
                const isAtBottom = orderItemsList.scrollHeight - orderItemsList.scrollTop <= orderItemsList.clientHeight + 10;

                if (isScrollable && !isAtBottom) {
                    basketScrollIndicator.style.display = 'flex';
                }

                else {
                    basketScrollIndicator.style.display = 'none';
                }
            }

            // Update scroll indicator on scroll for basket
            const orderItemsListEl = document.getElementById('orderItemsList');

            if (orderItemsListEl) {
                orderItemsListEl.addEventListener('scroll', checkBasketScrollIndicator);
            }

            /**
     * Update item quantity in cart - prevent zoom on mobile
     * @param {number} index - Index of item in cart array
     * @param {number} newQuantity - New quantity value
     * @param {Event} event - Optional event object to prevent default
     */
            function updateQuantity(index, newQuantity, event) {

                // Prevent zoom on mobile
                if (event) {
                    event.preventDefault();
                    event.stopPropagation();
                }

                if (newQuantity < 1) {
                    removeItem(index);
                    return;
                }

                fetch('<?php echo $basePath; ?>/basket', {

                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    }

                    ,
                    body: `action=update&index=${index
                        }

                            &quantity=${newQuantity
                        }

                            `

                }).then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            cart[index].quantity = newQuantity;
                            renderCart();
                        } else {
                            alert(data.error || 'Failed to update quantity');
                            renderCart();
                        }
                    })
                    .catch(err => {
                        console.error('Update error:', err);
                        renderCart();
                    });
            }

            // Add event listeners for quantity buttons - mobile-friendly
            document.addEventListener('DOMContentLoaded', function () {
                // Store touch data for each button
                const touchData = new Map();

                // Helper function to find the qty-btn element (handles clicks on child elements)
                function findQtyButton(element) {
                    if (!element) return null;

                    if (element.classList && element.classList.contains('qty-btn')) {
                        return element;
                    }

                    return element.closest('.qty-btn');
                }

                // Handle touch events for mobile (prevent zoom, handle taps)
                document.addEventListener('touchstart', function (e) {
                    const button = findQtyButton(e.target);

                    if (button) {
                        const touch = e.touches[0];

                        touchData.set(button, {
                            startTime: Date.now(),
                            startY: touch.clientY,
                            startX: touch.clientX
                        });
                    }
                }

                    , {
                        passive: true
                    });

                // Handle touchend for mobile taps
                document.addEventListener('touchend', function (e) {
                    const button = findQtyButton(e.target);

                    if (button && touchData.has(button)) {
                        const data = touchData.get(button);
                        const touch = e.changedTouches[0];
                        const touchDuration = Date.now() - data.startTime;
                        const deltaY = Math.abs(touch.clientY - data.startY);
                        const deltaX = Math.abs(touch.clientX - data.startX);

                        // Only trigger if it's a quick tap (not a swipe)
                        if (touchDuration < 300 && deltaY < 10 && deltaX < 10) {
                            e.preventDefault();
                            e.stopPropagation();

                            const index = parseInt(button.dataset.index);
                            const action = button.dataset.action;

                            if (!isNaN(index) && action) {
                                const currentQty = parseInt(cart[index].quantity);

                                if (action === 'increase') {
                                    updateQuantity(index, currentQty + 1, e);
                                }

                                else if (action === 'decrease') {
                                    updateQuantity(index, currentQty - 1, e);
                                }
                            }
                        }

                        touchData.delete(button);
                    }
                }

                    , {
                        passive: false
                    });

                // Handle click events for desktop
                document.addEventListener('click', function (e) {
                    const button = findQtyButton(e.target);

                    if (button) {
                        e.preventDefault();
                        e.stopPropagation();

                        const index = parseInt(button.dataset.index);
                        const action = button.dataset.action;

                        if (!isNaN(index) && action) {
                            const currentQty = parseInt(cart[index].quantity);

                            if (action === 'increase') {
                                updateQuantity(index, currentQty + 1, e);
                            }

                            else if (action === 'decrease') {
                                updateQuantity(index, currentQty - 1, e);
                            }
                        }
                    }
                }

                    , true);
            });

            /**
         * Remove item from cart
         * @param {number} index - Index of item in cart array
         */
            function removeItem(index) {
                fetch('<?php echo $basePath; ?>/basket', {

                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    }

                    ,
                    body: `action=remove&index=${index
                        }

                        `

                }).then(() => {
                    cart.splice(index, 1);
                    renderCart();
                });
            }

            /**
             * Calculate and update order summary (subtotal, total)
             */
            const CURRENT_BRANCH_ID = <?php echo json_encode($selectedBranchId); ?>;
            const DELIVERY_MAX_DISTANCE = <?php echo floatval(getDeliverySettings($selectedBranchId)['max_distance'] ?? 25); ?>;
            let currentDeliveryFee = 0;
            let deliveryDistanceKm = 0;

            // Distance and Delivery Fee will be calculated via Backend API

            /**
             * Calculate and update order summary (subtotal, total)
             */
            async function updateOrderSummary() {
                // Helper function to parse price
                const parsePrice = (price) => {
                    if (typeof price === 'string') {
                        return parseFloat(price.replace(/Rs\./g, '').replace(/[^\d.]/g, '')) || 0;
                    }
                    return parseFloat(price) || 0;
                };

                const subtotal = cart.reduce((sum, item) => sum + (parsePrice(item.price) * item.quantity), 0);
                const hasItems = cart.length > 0;

                const orderSubtotalEl = document.getElementById('orderSubtotal');
                const orderTotalEl = document.getElementById('orderTotal');
                const deliveryFeeRow = document.getElementById('deliveryFeeRow');
                const distanceRow = document.getElementById('distanceRow');
                const orderDeliveryFeeText = document.getElementById('orderDeliveryFeeText');
                const orderDistanceEl = document.getElementById('orderDistance');

                const friendState = (typeof window.getFriendDeliveryState === 'function') ? window.getFriendDeliveryState() : null;
                const isFriendMode = friendState && friendState.active;

                // Hoist location vars above if/else so they're in scope for button state check
                const userLat = parseFloat(document.getElementById('location_lat')?.value) || <?php echo json_encode($user['location_lat'] ?? null); ?>;
                const userLng = parseFloat(document.getElementById('location_lng')?.value) || <?php echo json_encode($user['location_lng'] ?? null); ?>;
                const hasValidLocation =
                    typeof userLat === 'number' && !isNaN(userLat) &&
                    typeof userLng === 'number' && !isNaN(userLng);

                if (orderSubtotalEl) orderSubtotalEl.textContent = `Rs. ${subtotal.toFixed(2)}`;

                if (isFriendMode) {
                    // Use already calculated friend fee
                    currentDeliveryFee = friendState.fee || 0;
                    deliveryDistanceKm = friendState.distance || 0;
                    
                    if (distanceRow) distanceRow.style.display = 'none';
                    if (deliveryFeeRow) {
                        deliveryFeeRow.style.display = 'flex';
                        if (orderDeliveryFeeText) orderDeliveryFeeText.textContent = `Rs. ${currentDeliveryFee.toFixed(2)}`;
                    }
                } else {

                    if (hasValidLocation && hasItems) {
                        try {
                            const response = await fetch('<?php echo $basePath; ?>/api/location/calc_delivery.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ 
                                    lat: userLat, 
                                    lng: userLng,
                                    branch_id: CURRENT_BRANCH_ID
                                })
                            });
                            const data = await response.json();

                            if (data.success) {
                                deliveryDistanceKm = parseFloat(data.distance_km);
                                currentDeliveryFee = parseFloat(data.delivery_fee);
                                const currentMax = parseFloat(data.max_distance) || DELIVERY_MAX_DISTANCE;

                                // Check for distance limit (UI will reflect this, but modal only on click)
                                if (deliveryDistanceKm > currentMax) {
                                    // showDistanceLimitModal(deliveryDistanceKm); // Removed automatic popup
                                }
                            } else {
                                console.warn(data.status + ": " + (data.error || "Unable to calculate delivery distance"));
                                deliveryDistanceKm = 0;
                                currentDeliveryFee = 0;
                            }
                        } catch (error) {
                            console.warn("Unable to calculate delivery distance. Please use current location and try again.");
                            deliveryDistanceKm = 0;
                            currentDeliveryFee = 0;
                        }

                        // Keep distance calculation for fee logic but hide it from the UI
                        if (distanceRow) {
                            distanceRow.style.display = 'none';
                        }
                        if (deliveryFeeRow) {
                            deliveryFeeRow.style.display = 'flex';
                            if (orderDeliveryFeeText) orderDeliveryFeeText.textContent = `Rs. ${currentDeliveryFee.toFixed(2)}`;
                        }
                    } else {
                        currentDeliveryFee = 0;
                        deliveryDistanceKm = 0;
                        if (distanceRow) distanceRow.style.display = 'none';
                        if (deliveryFeeRow) deliveryFeeRow.style.display = 'none';
                    }
                }

                const total = subtotal + currentDeliveryFee;
                if (orderTotalEl) orderTotalEl.textContent = `Rs. ${total.toFixed(2)}`;

                // Update order button state
                const orderNowBtn = document.getElementById('orderNowBtn');
                if (orderNowBtn) {
                    // Disable if no items OR delivery distance cannot be calculated (when delivery fee is zero despite having location)
                    // OR if distance exceeds limit
                    if (!hasItems) {
                        orderNowBtn.disabled = true;
                    } else if (friendState && friendState.active) {
                        // In Friend Mode, check friend distance
                        if (friendState.distance === 0) {
                            orderNowBtn.disabled = true;
                        } else {
                            // Keep enabled even if distance > limit, so click can show modal explanation
                            orderNowBtn.disabled = false;
                        }
                    } else if (hasValidLocation) {
                        // Normal mode with location
                        if (deliveryDistanceKm === 0) {
                            orderNowBtn.disabled = true;
                        } else {
                            // Keep enabled even if distance > limit, so click can show modal explanation
                            orderNowBtn.disabled = false;
                        }
                    } else {
                        // No location provided for delivery
                        orderNowBtn.disabled = true;
                    }
                }
            }

            /**
             * PERMANENT DELIVERY RESTRICTION: This ensures users cannot order if outside range.
             * Professional Flow: Keep button enabled if location exists, but block/modal on click.
             */

            /**
             * Display Distance Limit Modal (Premium Apology)
             */
            function showDistanceLimitModal(distance) {
                const modal = document.getElementById('distanceLimitModal');
                if (!modal) return;
                
                // Prevent body scroll
                const scrollY = window.scrollY;
                document.body.style.overflow = 'hidden';
                document.body.style.position = 'fixed';
                document.body.style.width = '100%';
                document.body.style.top = `-${scrollY}px`;

                modal.classList.add('active');
            }

            function closeDistanceLimitModal() {
                const modal = document.getElementById('distanceLimitModal');
                if (modal) {
                    // Restore body scroll
                    const scrollY = document.body.style.top;
                    document.body.style.overflow = '';
                    document.body.style.position = '';
                    document.body.style.width = '';
                    document.body.style.top = '';

                    if (scrollY) {
                        window.scrollTo(0, parseInt(scrollY || '0') * -1);
                    }

                    modal.classList.remove('active');

                    // Professional Redirect to Home
                    setTimeout(() => {
                        window.location.href = '<?php echo $basePath; ?>/index.php';
                    }, 300);
                }
            }

            // Instruction Modal Functions
            function openInstructionModal() {
                const modal = document.getElementById('instructionImageModal');
                if (modal) {
                    const scrollY = window.scrollY;
                    document.body.style.overflow = 'hidden';
                    document.body.style.position = 'fixed';
                    document.body.style.width = '100%';
                    document.body.style.top = `-${scrollY}px`;
                    modal.classList.add('active');
                }
            }

            function closeInstructionModal() {
                const modal = document.getElementById('instructionImageModal');
                if (modal) {
                    const scrollY = document.body.style.top;
                    document.body.style.overflow = '';
                    document.body.style.position = '';
                    document.body.style.width = '';
                    document.body.style.top = '';
                    if (scrollY) {
                        window.scrollTo(0, parseInt(scrollY || '0') * -1);
                    }
                    modal.classList.remove('active');
                }
            }

            // Initial UI Update
            document.addEventListener('DOMContentLoaded', () => {
                updateOrderSummary();
            });

            // ============================================================================
            // LIVE CLOCK & STORE STATUS MANAGER
            // ============================================================================
            (function () {
                const CURRENT_BRANCH_ID = <?php echo (int)($selectedBranchId ?? 0); ?>;
                const CONFIG = {
                    timezone: '<?php echo $restaurantTimezone; ?>',
                    openingTime: '<?php echo $openingTime; ?>',
                    closingTime: '<?php echo $closingTime; ?>',
                    isClosedManual: <?php echo $isClosedManual ? 'true' : 'false'; ?>
                };

                const elements = {
                    clock: {
                        hours: document.querySelector('.store-clock-hours'),
                        minutes: document.querySelector('.store-clock-minutes'),
                        seconds: document.querySelector('.store-clock-seconds'),
                        period: document.querySelector('.store-clock-period')
                    },
                    status: {
                        text: document.getElementById('statusText'),
                        label: document.getElementById('timerLabel'),
                        timer: document.getElementById('openingTimer'),
                        targetDisplay: document.getElementById('targetTimeDisplay'),
                        icon: document.querySelector('.status-icon'),
                        container: document.querySelector('.store-status')
                    },
                    orderBtn: document.getElementById('orderNowBtn')
                };

                function getRestaurantTime() {
                    try {
                        if (!CONFIG.timezone || typeof Intl === 'undefined') {
                            return new Date();
                        }
                        const now = new Date();
                        const options = { timeZone: CONFIG.timezone, hour12: false, year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric', second: 'numeric' };
                        const formatter = new Intl.DateTimeFormat('en-US', options);
                        const parts = formatter.formatToParts(now);
                        const dateParts = {};
                        parts.forEach(p => dateParts[p.type] = p.value);
                        const y = parseInt(dateParts.year, 10);
                        const mo = parseInt(dateParts.month, 10) - 1;
                        const d = parseInt(dateParts.day, 10);
                        const h = parseInt(dateParts.hour, 10);
                        const mi = parseInt(dateParts.minute, 10);
                        const s = parseInt(dateParts.second, 10);
                        return new Date(y, mo, d, h, mi, s);
                    } catch (e) {
                        return new Date();
                    }
                }
                window.getRestaurantTime = getRestaurantTime;

                function updateClock(now) {
                    let hours = now.getHours();
                    const minutes = now.getMinutes();
                    const seconds = now.getSeconds();
                    const period = hours >= 12 ? 'PM' : 'AM';

                    hours = hours % 12;
                    hours = hours ? hours : 12; // '0' should be '12'

                    // Use requestAnimationFrame for smoother updates if desired, 
                    // but simple textContent update is efficient enough for seconds.
                    if (elements.clock.hours) elements.clock.hours.textContent = String(hours).padStart(2, '0');
                    if (elements.clock.minutes) elements.clock.minutes.textContent = String(minutes).padStart(2, '0');
                    if (elements.clock.seconds) elements.clock.seconds.textContent = String(seconds).padStart(2, '0');
                    if (elements.clock.period) elements.clock.period.textContent = period;
                }

                // Expose config update globally
                window.updateRestaurantConfig = function (newStatus) {
                    if (newStatus.opening_time) CONFIG.openingTime = newStatus.opening_time;
                    if (newStatus.closing_time) CONFIG.closingTime = newStatus.closing_time;
                    if (newStatus.is_closed !== undefined) CONFIG.isClosedManual = newStatus.is_closed;
                    updateStatus(getRestaurantTime());
                };

                function updateStatus(now) {
                    const [openH, openM] = CONFIG.openingTime.split(':').map(Number);
                    const [closeH, closeM] = CONFIG.closingTime.split(':').map(Number);

                    const currentMinutes = now.getHours() * 60 + now.getMinutes();
                    const openMinutes = openH * 60 + openM;
                    const closeMinutes = closeH * 60 + closeM;

                    // Determine if the *range* is overnight (e.g., 11:00 AM to 02:00 AM)
                    const isOvernightRange = closeMinutes < openMinutes;

                    let isOpen = false;
                    if (isOvernightRange) {
                        // Open if: (Time >= Open) OR (Time < Close)
                        isOpen = (currentMinutes >= openMinutes) || (currentMinutes < closeMinutes);
                    } else {
                        // Open if: (Time >= Open) AND (Time < Close)
                        isOpen = (currentMinutes >= openMinutes) && (currentMinutes < closeMinutes);
                    }

                    // FORCE CLOSED IF MANUAL OVERRIDE IS ON
                    if (CONFIG.isClosedManual) {
                        isOpen = false;
                    }

                    // Expose status globally for modal logic
                    window.isRestaurantOpen = isOpen;

                    const formatTargetTime = (h, m) => {
                        const period = h >= 12 ? 'PM' : 'AM';
                        // Just AM/PM only
                        return `<span style="font-weight: 800; color: #000; margin-left: 2px;">${period}</span>`;
                    };

                    // Update Status Text & Icon
                    if (isOpen) {
                        if (elements.status.text) {
                            elements.status.text.textContent = "We're Open";
                            elements.status.text.style.color = '#4CAF50';
                        }
                        if (elements.status.icon) elements.status.icon.style.color = '#4CAF50';
                        if (elements.status.label) elements.status.label.textContent = "CLOSES IN:";

                        if (elements.status.targetDisplay) {
                            elements.status.targetDisplay.innerHTML = formatTargetTime(closeH, closeM);
                        }

                        updateCountdown(now, closeH, closeM, isOpen);
                    } else {
                        if (elements.status.text) {
                            // When emergency/event mode is ON, clearly show that we are closed
                            elements.status.text.textContent = CONFIG.isClosedManual ? "We're Currently Closed" : "We're Closed";
                            elements.status.text.style.color = '#FF5252';
                        }
                        if (elements.status.icon) elements.status.icon.style.color = '#FF5252';

                        if (CONFIG.isClosedManual) {
                            if (elements.status.label) elements.status.label.textContent = "WE'RE CURRENTLY CLOSED";
                            if (elements.status.targetDisplay) elements.status.targetDisplay.innerHTML = "";
                            // Show countdown as 00:00:00
                            const countdownWrapper = document.querySelector('.store-status-countdown');
                            if (countdownWrapper) countdownWrapper.style.opacity = '1';

                            if (elements.status.timer) {
                                const spans = elements.status.timer.querySelectorAll('span');
                                if (spans.length >= 3) {
                                    spans[0].textContent = '00';
                                    spans[1].textContent = '00';
                                    spans[2].textContent = '00';
                                }
                            }
                        } else {
                            if (elements.status.label) elements.status.label.textContent = "OPENS IN:";
                            if (elements.status.targetDisplay) {
                                elements.status.targetDisplay.innerHTML = formatTargetTime(openH, openM);
                            }
                            const countdownWrapper = document.querySelector('.store-status-countdown');
                            if (countdownWrapper) countdownWrapper.style.opacity = '1';
                            updateCountdown(now, openH, openM, isOpen);
                        }
                    }

                    // Update order button state (Source of truth is updateOrderSummary)
                    if (elements.orderBtn) {
                        const btnText = elements.orderBtn.querySelector('#orderBtnText');
                        if (btnText) btnText.textContent = 'ORDER NOW';
                        // Removed direct .disabled = false override to respect delivery distance limits
                    }
                }

                function updateCountdown(now, targetH, targetM, isOpen) {
                    let targetDate = new Date(now);
                    targetDate.setHours(targetH, targetM, 0, 0);

                    // If target time is earlier than now, it must be tomorrow
                    // (e.g. It's 11PM, closing is 2AM tomorrow)
                    if (targetDate <= now) {
                        targetDate.setDate(targetDate.getDate() + 1);
                    }

                    const diff = targetDate - now;
                    const hours = Math.floor(diff / (1000 * 60 * 60));
                    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((diff % (1000 * 60)) / 1000);

                    if (elements.status.timer) {
                        const spans = elements.status.timer.querySelectorAll('span');
                        if (spans.length >= 3) {
                            spans[0].textContent = String(hours).padStart(2, '0');
                            spans[1].textContent = String(minutes).padStart(2, '0');
                            spans[2].textContent = String(seconds).padStart(2, '0');
                        }
                    }
                }

                async function syncSettings() {
                    try {
                        const branchIdParam = CURRENT_BRANCH_ID ? `&branch_id=${CURRENT_BRANCH_ID}` : '';
                        const response = await fetch('api/restaurant_settings.php?t=' + Date.now() + branchIdParam);
                        const data = await response.json();
                        if (data) {
                            let changed = false;
                            if (CONFIG.openingTime !== data.opening_time) { CONFIG.openingTime = data.opening_time; changed = true; }
                            if (CONFIG.closingTime !== data.closing_time) { CONFIG.closingTime = data.closing_time; changed = true; }
                            const isManualClosed = (data.is_closed === true || data.is_closed === "true" || data.is_closed === 1 || data.is_closed === "1");
                            if (CONFIG.isClosedManual !== isManualClosed) { CONFIG.isClosedManual = isManualClosed; changed = true; }

                            if (changed) {
                                tick();
                            }
                        }
                    } catch (e) {
                        // Silent fail for sync
                    }
                }

                function tick() {
                    const now = getRestaurantTime();
                    updateClock(now);
                    updateStatus(now);
                }

                // Start
                setInterval(tick, 1000);
                setInterval(syncSettings, 1000); // Super fast real-time sync
                tick(); // Initial call
            })();

            // ============================================================================
            // OPERATING HOURS MODAL
            // ============================================================================
            // Displays restaurant delivery hours
            // ============================================================================
            (function () {
                const modal = document.getElementById('operatingHoursModal');
                const viewBtn = document.getElementById('viewOperatingHoursBtn');
                const closeBtn = document.getElementById('closeOperatingHoursBtn');
                const backdrop = modal ? modal.querySelector('.operating-hours-backdrop') : null;
                const hoursTableBody = document.getElementById('hoursTableBody');

                if (!modal || !viewBtn || !hoursTableBody) return;

                function renderHours() {
                    if (!hoursTableBody) return;

                    const openTime = "<?php echo date('g:i A', strtotime($openingTime)); ?>";
                    const closeTime = "<?php echo date('g:i A', strtotime($closingTime)); ?>";

                    hoursTableBody.innerHTML = `
                            <div class="hours-simple-message">
                                <div class="hours-icon">⏰</div>
                                <div class="hours-text">
                                    <h3>Daily Delivery Hours</h3>
                                    <p class="hours-time">${openTime} - ${closeTime}</p>
                                </div>
                            </div>
                        `;
                }

                function showModal() {
                    // Prevent body scroll
                    document.body.style.overflow = 'hidden';
                    document.body.style.position = 'fixed';
                    document.body.style.width = '100%';

                    document.body.style.top = `-${window.scrollY
                        }

                            px`;

                    modal.classList.add('active');
                    renderHours();
                }

                function closeModal() {
                    // Restore body scroll
                    const scrollY = document.body.style.top;
                    document.body.style.overflow = '';
                    document.body.style.position = '';
                    document.body.style.width = '';
                    document.body.style.top = '';

                    if (scrollY) {
                        window.scrollTo(0, parseInt(scrollY || '0') * -1);
                    }

                    modal.classList.remove('active');
                }

                // Open modal
                viewBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    showModal();
                });

                // Close modal
                if (closeBtn) {
                    closeBtn.addEventListener('click', closeModal);
                }

                if (backdrop) {
                    backdrop.addEventListener('click', closeModal);
                }

                // Close on Escape key
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape' && modal.classList.contains('active')) {
                        closeModal();
                    }
                });

                // Initial render
                renderHours(currentService);
            })();

            // ============================================================================
            // WALKING CHARACTER VIDEO PROCESSING
            // ============================================================================
            // Handles chroma key (green screen) removal for walking character animation
            // Processes video frames in real-time using canvas
            // ============================================================================
            (function () {
                const video = document.getElementById('walkerVideo');
                const canvas = document.getElementById('walkerCanvas');
                if (!video || !canvas) return;

                const ctx = canvas.getContext('2d');
                let animationFrameId = null;
                let isProcessing = false;
                let displayWidth = 120;
                let displayHeight = 170;

                // Set canvas size - ensure full character including head is visible
                function setCanvasSize() {
                    if (video.videoWidth && video.videoHeight) {
                        const aspectRatio = video.videoHeight / video.videoWidth;
                        const isMobile = window.innerWidth <= 768;
                        const isSmallMobile = window.innerWidth <= 480;

                        if (isSmallMobile) {
                            displayWidth = 90;
                            displayHeight = Math.max(Math.round(displayWidth * aspectRatio), 110);
                        }

                        else if (isMobile) {
                            displayWidth = 100;
                            displayHeight = Math.max(Math.round(displayWidth * aspectRatio), 120);
                        }

                        else {
                            displayWidth = 120;
                            displayHeight = Math.max(Math.round(displayWidth * aspectRatio), 170);
                        }

                        const dpr = window.devicePixelRatio || 2;
                        const scale = Math.min(dpr, 2.5);

                        canvas.width = displayWidth * scale;
                        canvas.height = displayHeight * scale;
                        canvas.style.width = displayWidth + 'px';
                        canvas.style.height = displayHeight + 'px';
                        ctx.setTransform(scale, 0, 0, scale, 0, 0);
                        ctx.imageSmoothingEnabled = true;
                        ctx.imageSmoothingQuality = 'high';
                    }
                }

                function chromaKey() {
                    const width = canvas.width;
                    const height = canvas.height;
                    if (width === 0 || height === 0) return;

                    const imageData = ctx.getImageData(0, 0, width, height);
                    const data = imageData.data;

                    for (let i = 0; i < data.length; i += 4) {
                        const r = data[i];
                        const g = data[i + 1];
                        const b = data[i + 2];
                        const isGreenDominant = (g > 90) && (g > r + 30) && (g > b + 30);

                        if (isGreenDominant) {
                            data[i + 3] = 0;
                        }

                        else if ((g > 70) && (g > r + 15) && (g > b + 15)) {
                            data[i + 3] = Math.min(data[i + 3], 100);
                        }
                    }

                    ctx.putImageData(imageData, 0, 0);
                }

                function drawFrame() {
                    if (!video.paused && video.readyState >= video.HAVE_CURRENT_DATA) {
                        if (canvas.width === 0 || canvas.height === 0) {
                            setCanvasSize();
                        }

                        if (canvas.width > 0 && canvas.height > 0 && video.videoWidth > 0 && video.videoHeight > 0) {
                            const videoAspect = video.videoHeight / video.videoWidth;
                            ctx.clearRect(0, 0, displayWidth, displayHeight);

                            let drawWidth = displayWidth;
                            let drawHeight = drawWidth * videoAspect;
                            let drawX = 0;
                            let drawY = (displayHeight - drawHeight) / 2;

                            ctx.drawImage(video, 0, 0, video.videoWidth, video.videoHeight, drawX, drawY, drawWidth, drawHeight);
                            chromaKey();
                        }
                    }

                    if (isProcessing) {
                        animationFrameId = requestAnimationFrame(drawFrame);
                    }
                }

                function startProcessing() {
                    if (!isProcessing) {
                        isProcessing = true;
                        setCanvasSize();
                        drawFrame();
                    }
                }

                video.addEventListener('loadedmetadata', function () {
                    setCanvasSize();
                    video.play().catch(e => console.log('Video play error:', e));
                });

                video.addEventListener('canplay', function () {
                    startProcessing();
                });

                video.addEventListener('play', function () {
                    if (!isProcessing) {
                        startProcessing();
                    }
                });

                video.addEventListener('loadeddata', function () {
                    setCanvasSize();
                });

                function reloadVideo() {
                    const source = document.getElementById('videoSource');
                    if (!source) return;
                    const currentSrc = source.getAttribute('src');
                    const separator = currentSrc.includes('?') ? '&' : '?';
                    source.setAttribute('src', currentSrc + separator + '_t=' + Date.now());
                    video.load();
                }

                reloadVideo();

                document.addEventListener('visibilitychange', function () {
                    if (!document.hidden) {
                        reloadVideo();
                    }
                });

                let resizeTimeout;

                window.addEventListener('resize', function () {
                    clearTimeout(resizeTimeout);

                    resizeTimeout = setTimeout(function () {
                        setCanvasSize();
                    }

                        , 250);
                });

                if (video.readyState >= video.HAVE_METADATA) {
                    setCanvasSize();
                    video.play().catch(e => console.log('Video play error:', e));
                    setTimeout(startProcessing, 100);
                }

                else {
                    setTimeout(reloadVideo, 100);
                }

                window.addEventListener('beforeunload', function () {
                    if (animationFrameId) {
                        cancelAnimationFrame(animationFrameId);
                    }
                });
            })();

            // ============================================================================
            // MOBILE MENU TOGGLE
            // ============================================================================
            // Handles hamburger menu open/close functionality for mobile navigation
            // Prevents body scroll when menu is open
            // ============================================================================


            // ============================================================================
            // INITIALIZATION
            // ============================================================================
            // Initialize cart display on page load
            // ============================================================================

            renderCart();



            // Delivery Location Dropdown removed

            // ============================================================================
            // PICKUP TIME SELECTION DROPDOWN
            // ============================================================================
            // Handles pickup time selection with validation
            // Ensures selected time is within restaurant operating hours
            // ============================================================================
            /*
        (function () {
            const times = [
                '12:00 PM', '12:30 PM', '01:00 PM', '01:30 PM', '02:00 PM', '02:30 PM',
                '03:00 PM', '03:30 PM', '04:00 PM', '04:30 PM', '05:00 PM', '05:30 PM',
                '06:00 PM', '06:30 PM', '07:00 PM', '07:30 PM', '08:00 PM', '08:30 PM',
                '09:00 PM', '09:30 PM'
            ];
 
            const MIN_TIME = '12:00 PM';
            const MAX_TIME = '09:30 PM';
 
            const dropdown = document.getElementById('basketPickupTimeDropdown');
            const dropdownInput = document.getElementById('pickupTime');
            const dropdownList = document.getElementById('basketPickupTimeList');
            const optionsList = document.getElementById('basketPickupTimeOptionsList');
            const hiddenInput = document.getElementById('pickupTimeValue');
 
            if (!dropdown || !dropdownInput || !dropdownList) return;
 
            let selectedValue = '06:30 PM';
 
            function timeToMinutes(timeStr) {
                const [time, period] = timeStr.split(' ');
                const [hours, minutes] = time.split(':').map(Number);
                let totalMinutes = hours * 60 + minutes;
                if (period === 'PM' && hours !== 12) {
                    totalMinutes += 12 * 60;
                } else if (period === 'AM' && hours === 12) {
                    totalMinutes -= 12 * 60;
                }
                return totalMinutes;
            }
 
            function isValidTime(timeStr) {
                if (!timeStr) return false;
                const timePattern = /^(\d{1,2}):(\d{2})\s?(AM|PM)$/i;
                if (!timePattern.test(timeStr.trim())) return false;
 
                const inputMinutes = timeToMinutes(timeStr.trim().toUpperCase());
                const minMinutes = timeToMinutes(MIN_TIME);
                const maxMinutes = timeToMinutes(MAX_TIME);
 
                return inputMinutes >= minMinutes && inputMinutes <= maxMinutes;
            }
 
            function formatTime(timeStr) {
                const timePattern = /^(\d{1,2}):(\d{2})\s?(AM|PM)$/i;
                const match = timeStr.trim().match(timePattern);
                if (!match) return null;
 
                let hours = parseInt(match[1]);
                const minutes = match[2];
                const period = match[3].toUpperCase();
 
                if (period === 'PM' && hours !== 12) {
                    hours += 12;
                } else if (period === 'AM' && hours === 12) {
                    hours = 0;
                }
 
                if (hours === 0) {
                    hours = 12;
                    return `${hours.toString().padStart(2, '0')}:${minutes} AM`;
                } else if (hours === 12) {
                    return `${hours.toString().padStart(2, '0')}:${minutes} PM`;
                } else if (hours > 12) {
                    hours -= 12;
                    return `${hours.toString().padStart(2, '0')}:${minutes} PM`;
                } else {
                    return `${hours.toString().padStart(2, '0')}:${minutes} AM`;
                }
            }
 
            function showError(message) {
                dropdownInput.style.borderColor = '#ff4444';
                dropdownInput.style.boxShadow = '0 0 0 3px rgba(255, 68, 68, 0.1)';
 
                const existingError = dropdown.querySelector('.time-error-message');
                if (existingError) existingError.remove();
 
                const errorMsg = document.createElement('div');
                errorMsg.className = 'time-error-message';
                errorMsg.textContent = message;
                errorMsg.style.cssText = 'color: #ff4444; font-size: 12px; margin-top: 6px; font-weight: 500;';
                dropdown.appendChild(errorMsg);
 
                setTimeout(() => {
                    dropdownInput.style.borderColor = '';
                    dropdownInput.style.boxShadow = '';
                    if (errorMsg.parentNode) errorMsg.remove();
                }, 3000);
            }
 
            function clearError() {
                dropdownInput.style.borderColor = '';
                dropdownInput.style.boxShadow = '';
                const errorMsg = dropdown.querySelector('.time-error-message');
                if (errorMsg) errorMsg.remove();
            }
 
            function renderOptions() {
                optionsList.innerHTML = '';
                times.forEach(time => {
                    const option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'dropdown-option' + (time === selectedValue ? ' selected' : '');
                    option.textContent = time;
                    option.addEventListener('click', () => selectOption(time));
                    optionsList.appendChild(option);
                });
            }
 
            function selectOption(time) {
                if (!isValidTime(time)) {
                    showError(`Pickup time must be between ${MIN_TIME} and ${MAX_TIME}`);
                    return;
                }
 
                selectedValue = time;
                dropdownInput.value = time;
                if (hiddenInput) hiddenInput.value = time;
                dropdown.classList.remove('active');
                clearError();
                renderOptions();
 
                const selectedOption = optionsList.querySelector('.dropdown-option.selected');
                if (selectedOption) {
                    selectedOption.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
 
            dropdownInput.addEventListener('click', (e) => {
                e.stopPropagation();
                dropdown.classList.toggle('active');
                if (dropdown.classList.contains('active')) {
                    setTimeout(() => {
                        const selectedOption = optionsList.querySelector('.dropdown-option.selected');
                        if (selectedOption) {
                            selectedOption.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }, 100);
                }
            });
 
            dropdownInput.addEventListener('input', (e) => {
                const inputValue = e.target.value.trim();
                if (inputValue === '') {
                    clearError();
                    return;
                }
 
                const formatted = formatTime(inputValue);
                if (formatted) {
                    if (isValidTime(formatted)) {
                        selectedValue = formatted;
                        dropdownInput.value = formatted;
                        if (hiddenInput) hiddenInput.value = formatted;
                        clearError();
                        renderOptions();
                    } else {
                        showError(`Pickup time must be between ${MIN_TIME} and ${MAX_TIME}`);
                    }
                }
            });
 
            dropdownInput.addEventListener('blur', (e) => {
                const inputValue = e.target.value.trim();
                if (inputValue && !isValidTime(inputValue)) {
                    showError(`Pickup time must be between ${MIN_TIME} and ${MAX_TIME}`);
                    if (selectedValue) {
                        dropdownInput.value = selectedValue;
                        if (hiddenInput) hiddenInput.value = selectedValue;
                    }
                } else {
                    clearError();
                }
            });
 
            document.addEventListener('click', (e) => {
                if (!dropdown.contains(e.target)) {
                    dropdown.classList.remove('active');
                    const inputValue = dropdownInput.value.trim();
                    if (inputValue && !isValidTime(inputValue)) {
                        showError(`Pickup time must be between ${MIN_TIME} and ${MAX_TIME}`);
                        if (selectedValue) {
                            dropdownInput.value = selectedValue;
                            if (hiddenInput) hiddenInput.value = selectedValue;
                        }
                    }
                }
            });
 
            dropdownInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    const inputValue = dropdownInput.value.trim();
                    if (inputValue && isValidTime(inputValue)) {
                        selectOption(inputValue);
                    } else if (inputValue) {
                        showError(`Pickup time must be between ${MIN_TIME} and ${MAX_TIME}`);
                    } else {
                        dropdown.classList.toggle('active');
                    }
                }
            });
 
            dropdownInput.addEventListener('focus', () => {
                dropdownInput.readOnly = false;
            });
 
            renderOptions();
        })();
        */

            // ============================================================================
            // RESTAURANT STATUS CHECKING
            // ============================================================================
            // Functions to check if restaurant is currently open or closed
            // ============================================================================

            /**
         * Check if restaurant is currently closed based on Sydney time
         * @returns {boolean} True if restaurant is closed, false if open
         */
            function isRestaurantClosed() {
                const now = getRestaurantTime();
                const openingTimeStr = '<?php echo $openingTime; ?>';
                const closingTimeStr = '<?php echo $closingTime; ?>';

                const [openH, openM] = openingTimeStr.split(':').map(Number);
                const [closeH, closeM] = closingTimeStr.split(':').map(Number);

                const currentTime24 = now.getHours() * 60 + now.getMinutes();
                const openingTime24 = openH * 60 + openM;
                const closingTime24 = closeH * 60 + closeM;

                if (closingTime24 < openingTime24) {
                    // Overnight range: e.g. 11 AM - 6 AM (next day)
                    // The "Closed Gap" is between closeTime and openTime on the SAME day
                    // Closed if: (Time >= Close) AND (Time < Open)
                    return (currentTime24 >= closingTime24 && currentTime24 < openingTime24);
                } else {
                    // Normal range: e.g. 9 AM - 10 PM
                    // Closed if: (Time < Open) OR (Time >= Close)
                    return (currentTime24 < openingTime24 || currentTime24 >= closingTime24);
                }
            }

            // Live clock update function for restaurant closed modal
            let restaurantClosedClockInterval = null;

            function updateRestaurantClosedClock() {
                const clockEl = document.getElementById('restaurantClosedClock');
                if (!clockEl) return;

                const now = getRestaurantTime();
                let hours = now.getHours();
                const minutes = now.getMinutes();
                const seconds = now.getSeconds();
                const period = hours >= 12 ? 'PM' : 'AM';

                // Convert to 12-hour format
                if (hours === 0) {
                    hours = 12;
                }

                else if (hours > 12) {
                    hours = hours - 12;
                }

                const hoursEl = clockEl.querySelector('.clock-hours');
                const minutesEl = clockEl.querySelector('.clock-minutes');
                const secondsEl = clockEl.querySelector('.clock-seconds');
                const periodEl = clockEl.querySelector('.clock-period');

                if (hoursEl) hoursEl.textContent = String(hours).padStart(2, '0');
                if (minutesEl) minutesEl.textContent = String(minutes).padStart(2, '0');
                if (secondsEl) secondsEl.textContent = String(seconds).padStart(2, '0');
                if (periodEl) periodEl.textContent = period;
            }

            // (Redundant showRestaurantClosedModal definitions removed - standardized version at line 4770 handles this)


            // ============================================================================
            // OPERATING HOURS MODAL
            // ============================================================================
            (function () {
                const modal = document.getElementById('operatingHoursModal');
                const btn = document.getElementById('viewOperatingHoursBtn');
                const closeBtn = document.getElementById('closeOperatingHoursBtn');
                const backdrop = modal ? modal.querySelector('.operating-hours-backdrop') : null;
                const hoursBody = document.getElementById('hoursTableBody');

                if (!modal || !btn || !hoursBody) return;

                function showModal() {
                    const openTime = "<?php echo date('g:i A', strtotime($openingTime)); ?>";
                    const closeTime = "<?php echo date('g:i A', strtotime($closingTime)); ?>";

                    hoursBody.innerHTML = `
                        <div class="hours-simple-message">
                            <div class="hours-icon">⏰</div>
                            <div class="hours-text">
                                <h3>Daily Delivery Hours</h3>
                                <p class="hours-time">${openTime} - ${closeTime}</p>
                            </div>
                        </div>
                    `;

                    // Prevent body scroll
                    const scrollY = window.scrollY;
                    document.body.style.overflow = 'hidden';
                    document.body.style.position = 'fixed';
                    document.body.style.width = '100%';
                    document.body.style.top = `-${scrollY}px`;

                    modal.classList.add('active');
                }

                function closeModal() {
                    // Restore body scroll
                    const scrollY = document.body.style.top;
                    document.body.style.overflow = '';
                    document.body.style.position = '';
                    document.body.style.width = '';
                    document.body.style.top = '';
                    if (scrollY) {
                        window.scrollTo(0, parseInt(scrollY || '0') * -1);
                    }
                    modal.classList.remove('active');
                }

                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    showModal();
                });

                if (closeBtn) closeBtn.addEventListener('click', closeModal);
                if (backdrop) backdrop.addEventListener('click', closeModal);

                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && modal.classList.contains('active')) {
                        closeModal();
                    }
                });
            })();


            // ============================================================================
            // MODAL HELPERS
            // ============================================================================

            /**
         * Display order success modal after successful order placement
         */
            function showOrderSuccessModal() {
                const modal = document.getElementById('orderSuccessModal');
                if (!modal) return;

                // Prevent body scroll
                const scrollY = window.scrollY;
                document.body.style.overflow = 'hidden';
                document.body.style.position = 'fixed';
                document.body.style.width = '100%';

                document.body.style.top = `-${scrollY
                    }

                    px`;

                modal.classList.add('active');

                // Close modal when clicking backdrop
                const backdrop = modal.querySelector('.order-success-backdrop');

                if (backdrop) {
                    // Remove any existing listeners
                    const newBackdrop = backdrop.cloneNode(true);
                    backdrop.parentNode.replaceChild(newBackdrop, backdrop);
                    newBackdrop.addEventListener('click', closeOrderSuccessModal);
                }

                // Close on Escape key
                const escapeHandler = function (e) {
                    if (e.key === 'Escape' && modal.classList.contains('active')) {
                        closeOrderSuccessModal();
                        document.removeEventListener('keydown', escapeHandler);
                    }
                }

                    ;
                document.addEventListener('keydown', escapeHandler);
            }

            /**
         * Close order success modal and restore page scroll
         */
            function closeOrderSuccessModal() {
                const modal = document.getElementById('orderSuccessModal');
                if (!modal) return;

                // Restore body scroll
                const scrollY = document.body.style.top;
                document.body.style.overflow = '';
                document.body.style.position = '';
                document.body.style.width = '';
                document.body.style.top = '';

                if (scrollY) {
                    window.scrollTo(0, parseInt(scrollY || '0') * -1);
                }

                modal.classList.remove('active');
            }

            /**
         * Display empty cart warning modal when user tries to order with empty cart
         */
            var emptyCartWarningEscapeHandler = null;

            function showEmptyCartWarning() {
                const modal = document.getElementById('emptyCartWarningModal');
                if (!modal) return;

                // Prevent multiple opens
                if (modal.classList.contains('active')) {
                    return;
                }

                // Prevent body scroll
                const scrollY = window.scrollY;
                document.body.style.overflow = 'hidden';
                document.body.style.position = 'fixed';
                document.body.style.width = '100%';

                document.body.style.top = `-${scrollY
                    }

                    px`;

                modal.classList.add('active');

                // Close modal when clicking backdrop (use event delegation to avoid conflicts)
                const backdrop = modal.querySelector('.empty-cart-warning-backdrop');

                if (backdrop) {
                    // Remove any existing listeners by cloning
                    const newBackdrop = backdrop.cloneNode(true);
                    backdrop.parentNode.replaceChild(newBackdrop, backdrop);

                    newBackdrop.addEventListener('click', function (e) {
                        e.stopPropagation();
                        closeEmptyCartWarning();
                    });
                }

                // Close on Escape key (remove old handler first)
                if (emptyCartWarningEscapeHandler) {
                    document.removeEventListener('keydown', emptyCartWarningEscapeHandler);
                }

                emptyCartWarningEscapeHandler = function (e) {
                    if (e.key === 'Escape' && modal.classList.contains('active')) {
                        closeEmptyCartWarning();
                    }
                }

                    ;
                document.addEventListener('keydown', emptyCartWarningEscapeHandler);
            }

            /**
         * Close empty cart warning modal
         */
            function closeEmptyCartWarning() {
                const modal = document.getElementById('emptyCartWarningModal');
                if (!modal) return;

                // Remove escape handler
                if (emptyCartWarningEscapeHandler) {
                    document.removeEventListener('keydown', emptyCartWarningEscapeHandler);
                    emptyCartWarningEscapeHandler = null;
                }

                // Restore body scroll
                const scrollY = document.body.style.top;
                document.body.style.overflow = '';
                document.body.style.position = '';
                document.body.style.width = '';
                document.body.style.top = '';

                if (scrollY) {
                    window.scrollTo(0, parseInt(scrollY || '0') * -1);
                }

                modal.classList.remove('active');
            }

            // ============================================================================
            // RESTAURANT CLOSED MODAL
            // ============================================================================
            function showRestaurantClosedModal() {
                const modal = document.getElementById('restaurantClosedModal');
                if (!modal) return;

                const scrollY = window.scrollY;
                document.body.style.overflow = 'hidden';
                document.body.style.position = 'fixed';
                document.body.style.width = '100%';
                document.body.style.top = `-${scrollY}px`;

                modal.classList.add('active');

                // Elements
                const clockEl = document.getElementById('restaurantClosedClock');
                const countdownEl = document.getElementById('modalCountdownValue');

                // Clock Hands
                const hourHand = document.getElementById('modalHourHand');
                const minuteHand = document.getElementById('modalMinuteHand');
                const secondHand = document.getElementById('modalSecondHand');

                // Get Opening Time
                const openingTimeStr = '<?php echo $openingTime; ?>';
                const [targetH, targetM] = openingTimeStr.split(':').map(Number);

                const updateModalContent = () => {
                    const now = new Date();
                    if (hourHand && minuteHand && secondHand) {
                        const s = now.getSeconds();
                        const m = now.getMinutes();
                        const h = now.getHours();
                        const sDeg = s * 6;
                        const mDeg = (m * 6) + (s * 0.1);
                        const hDeg = ((h % 12) * 30) + (m * 0.5);
                        secondHand.style.transform = `rotate(${sDeg}deg)`;
                        minuteHand.style.transform = `rotate(${mDeg}deg)`;
                        hourHand.style.transform = `rotate(${hDeg}deg)`;
                    }

                    if (clockEl) {
                        clockEl.innerHTML = `<span class="clock-hours">${String(now.getHours() % 12 || 12).padStart(2, '0')}</span><span class="clock-separator">:</span><span class="clock-minutes">${String(now.getMinutes()).padStart(2, '0')}</span><span class="clock-separator">:</span><span class="clock-seconds">${String(now.getSeconds()).padStart(2, '0')}</span> <span class="clock-period">${now.getHours() >= 12 ? 'PM' : 'AM'}</span>`;
                    }

                    if (countdownEl) {
                        let targetDate = new Date(now);
                        targetDate.setHours(targetH, targetM, 0, 0);
                        if (targetDate <= now) targetDate.setDate(targetDate.getDate() + 1);
                        const diff = targetDate - now;
                        const hRem = Math.floor(diff / (1000 * 60 * 60));
                        const mRem = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
                        const sRem = Math.floor((diff % (1000 * 60)) / 1000);
                        countdownEl.textContent = `${String(hRem).padStart(2, '0')}:${String(mRem).padStart(2, '0')}:${String(sRem).padStart(2, '0')}`;
                    }
                };

                updateModalContent();
                const intervalId = setInterval(updateModalContent, 1000);

                const closeModal = () => {
                    const scrollY = document.body.style.top;
                    document.body.style.overflow = '';
                    document.body.style.position = '';
                    document.body.style.width = '';
                    document.body.style.top = '';
                    if (scrollY) {
                        window.scrollTo(0, parseInt(scrollY || '0') * -1);
                    }
                    modal.classList.remove('active');
                    clearInterval(intervalId);
                };

                const closeBtn = document.getElementById('closeRestaurantClosedBtn');
                const backdrop = modal.querySelector('.restaurant-closed-backdrop');
                if (closeBtn) closeBtn.onclick = closeModal;
                if (backdrop) backdrop.onclick = closeModal;
            }


            // ============================================================================
            // ORDER PLACEMENT
            // ============================================================================
            document.addEventListener('DOMContentLoaded', function () {
                const orderNowBtn = document.getElementById('orderNowBtn');
                const paymentModal = document.getElementById('paymentMethodModal');
                const closePaymentModalBtn = document.getElementById('closePaymentModalBtn');
                const confirmPaymentBtn = document.getElementById('confirmPaymentBtn');

                if (orderNowBtn) {
                    orderNowBtn.addEventListener('click', function (e) {
                        // Check if restaurant is closed
                        if (typeof window.isRestaurantOpen !== 'undefined' && !window.isRestaurantOpen) {
                            e.preventDefault();
                            showRestaurantClosedModal();
                            return;
                        }

                        // RESTRICTION: Check for distance limit before proceeding
                        if (typeof deliveryDistanceKm !== 'undefined' && deliveryDistanceKm > DELIVERY_MAX_DISTANCE) {
                            e.preventDefault();
                            showDistanceLimitModal(deliveryDistanceKm);
                            return;
                        }

                        // Check for Friend distance if active
                        const friendStatus = (typeof window.getFriendDeliveryState === 'function') ? window.getFriendDeliveryState() : null;
                        if (friendStatus && friendStatus.active && friendStatus.distance > DELIVERY_MAX_DISTANCE) {
                            e.preventDefault();
                            showDistanceLimitModal(friendStatus.distance);
                            return;
                        }

                        if (this.disabled) return;

                        // Check if user is verified
                        const isLoggedIn =
                            <?php echo json_encode($isLoggedIn); ?>
                            ;
                        const isVerified =
                            <?php echo json_encode($isVerified); ?>
                            ;

                        if (isLoggedIn && !isVerified) {
                            if (typeof window.showVerificationModal === 'function') {
                                window.showVerificationModal();
                            }

                            else {
                                window.location.href = '<?php echo $basePath; ?>/profile';
                            }

                            return;
                        }

                        if (cart.length === 0) {
                            showEmptyCartWarning();
                            return;
                        }

                        // SHOW PAYMENT MODAL
                        if (paymentModal) {
                            const scrollY = window.scrollY;
                            document.body.style.overflow = 'hidden';
                            document.body.style.position = 'fixed';
                            document.body.style.width = '100%';
                            document.body.style.top = `-${scrollY}px`;
                            paymentModal.classList.add('active');
                        }
                    });
                }

                const closePaymentModal = () => {
                    if (paymentModal) {
                        const scrollY = document.body.style.top;
                        document.body.style.overflow = '';
                        document.body.style.position = '';
                        document.body.style.width = '';
                        document.body.style.top = '';
                        if (scrollY) {
                            window.scrollTo(0, parseInt(scrollY || '0') * -1);
                        }
                        paymentModal.classList.remove('active');
                    }
                };

                // Close Payment Modal
                if (closePaymentModalBtn && paymentModal) {
                    closePaymentModalBtn.addEventListener('click', closePaymentModal);
                }

                // Close on backdrop click
                if (paymentModal) {
                    const backdrop = paymentModal.querySelector('.payment-modal-backdrop');
                    if (backdrop) backdrop.addEventListener('click', closePaymentModal);
                }

                // Handle selection clicks for instant order (COD)
                // Handle selection clicks for payment options
                // Handle selection clicks for payment options
                if (paymentModal) {
                    const paymentOptions = paymentModal.querySelectorAll('.payment-option');

                    // Initialize selection state on load
                    paymentOptions.forEach(option => {
                        const input = option.querySelector('input');

                        if (input && input.checked) {
                            option.classList.add('selected');
                        }
                    });

                    paymentOptions.forEach(option => {
                        option.addEventListener('click', function (e) {
                            const input = this.querySelector('input');

                            if (input) {

                                // If click was NOT on the input itself, we need to check it manually
                                if (e.target.tagName !== 'INPUT') {
                                    input.checked = true;
                                }

                                // Update visual selection state
                                paymentOptions.forEach(opt => opt.classList.remove('selected'));
                                this.classList.add('selected');
                            }
                        });
                    });
                }

                // Handle Actual Submission from Modal
                const loadingModal = document.getElementById('loadingModal');

                if (confirmPaymentBtn && paymentModal) {
                    confirmPaymentBtn.addEventListener('click', function () {
                        // FINAL SECURITY CHECK: Validate distance one last time
                        const currentFriendState = (typeof window.getFriendDeliveryState === 'function') ? window.getFriendDeliveryState() : null;
                        const finalDistance = (currentFriendState && currentFriendState.active) ? currentFriendState.distance : deliveryDistanceKm;

                        if (finalDistance > DELIVERY_MAX_DISTANCE) {
                            alert("Sorry, your location is beyond our delivery range. We cannot process this order.");
                            showDistanceLimitModal(finalDistance);
                            closePaymentModal();
                            return;
                        }

                        const paymentMethod = document.querySelector('input[name="modal_payment_method"]:checked')?.value || 'cod';

                        // Close payment modal properly
                        closePaymentModal();

                        // Show loading modal
                        if (loadingModal) {
                            const scrollY = window.scrollY;
                            document.body.style.overflow = 'hidden';
                            document.body.style.position = 'fixed';
                            document.body.style.width = '100%';
                            document.body.style.top = `-${scrollY}px`;
                            loadingModal.classList.add('active');
                        }

                        // Create order object
                        const orderId = 'ORD' + Date.now() + Math.random().toString(36).substr(2, 5).toUpperCase();

                        const order = {

                            order_id: orderId,
                            items: cart.map(item => ({
                                name: item.name,
                                description: item.description || '',
                                price: item.price,
                                quantity: item.quantity,
                                image: item.image || 'assets/plate.png'

                            })),
                            service_type: 'delivery',
                            payment_method: paymentMethod,
                            delivery_address: (function () {
                                // Prefer live values from hidden inputs or UI
                                const street = document.getElementById('street_location')?.value || '';
                                const delivery = document.getElementById('delivery_location')?.value || '';
                                if (street || delivery) return [delivery, street].filter(Boolean).join(', ');

                                // Fallback to initial PHP value
                                return <?php echo json_encode(isUserLoggedIn() ? implode(', ', array_filter([$user['delivery_location'] ?? '', $user['street_location'] ?? ''])) : ''); ?>;
                            })(),
                            pickup_time: null,
                            user_lat: parseFloat(document.getElementById('location_lat')?.value) || null,
                            user_lng: parseFloat(document.getElementById('location_lng')?.value) || null,
                            delivery_fee: currentDeliveryFee,
                            distance_km: deliveryDistanceKm,
                            status: 'pending',
                            created_at: new Date().toISOString(),
                            updated_at: new Date().toISOString(),
                            restaurant_id: CURRENT_BRANCH_ID,
                            notes: document.getElementById('orderNote')?.value.trim() || ''
                        }

                            ;

                        // Save order to session and clear cart
                        fetch('<?php echo $basePath; ?>/order-tracking.php', {

                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            }

                            ,
                            body: `action=save_order&order=${encodeURIComponent(JSON.stringify(order))
                                }

                                    `

                        }).then(response => response.json()).then(data => {
                            // Hide loading modal
                            if (loadingModal) {
                                loadingModal.classList.remove('active');
                                document.body.style.overflow = '';
                                document.documentElement.style.overflow = '';
                            }

                            if (data.success) {

                                // Clear cart after successful order save
                                fetch('<?php echo $basePath; ?>/cart.php', {

                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/x-www-form-urlencoded',
                                    }

                                    ,
                                    body: 'action=clear'

                                }).then(response => response.json()).then(clearData => {
                                    if (clearData.success) {
                                        cart.splice(0, cart.length);
                                        renderCart();
                                        const cartCountElements = document.querySelectorAll('.cart-count, .navbar__cart-count, [data-cart-count]');

                                        cartCountElements.forEach(el => {
                                            el.textContent = '0';
                                            el.style.display = cart.length === 0 ? 'none' : 'inline';
                                        });
                                    }

                                    else {
                                        // Even if server returns failure on clear, we clear locally since order placed
                                        cart.splice(0, cart.length);
                                        renderCart();
                                    }

                                    // Redirect based on payment method
                                    if (data.payment_method === 'ONLINE') {
                                        // Redirect to eSewa payment start page
                                        const params = new URLSearchParams();
                                        params.append('order_id', data.db_id);
                                        // amount and uuid are not strictly needed by pay.php (it fetches from DB), but keeping order_id is crucial
                                        window.location.href = '<?php echo $basePath; ?>/esewa/esewa_payment_start.php?' + params.toString();
                                    }

                                    else {
                                        // Show success modal for COD
                                        showOrderSuccessModal();
                                    }

                                }).catch(clearErr => {
                                    cart.splice(0, cart.length);
                                    renderCart();

                                    // Redirect based on payment method even if clear failed
                                    if (data.payment_method === 'ONLINE') {
                                        const params = new URLSearchParams();
                                        params.append('order_id', data.db_id);
                                        window.location.href = '<?php echo $basePath; ?>/esewa/esewa_payment_start.php?' + params.toString();
                                    }

                                    else {
                                        showOrderSuccessModal();
                                    }
                                });
                            }

                            else {
                                alert((data && data.error) ? data.error : 'Failed to save order. Please try again.');
                                if (orderNowBtn) orderNowBtn.disabled = false;
                            }

                        }).catch(err => {
                            if (loadingModal) loadingModal.classList.remove('active');
                            console.error('Error saving order:', err);
                            alert('An error occurred. Please try again.');
                            if (orderNowBtn) orderNowBtn.disabled = false;
                        });
                    });
                }
            });


            // ============================================================================
            // LEAFLET MAP INITIALIZATION
            // ============================================================================
            (function () {
                document.addEventListener('DOMContentLoaded', function () {
                    const mapContainer = document.getElementById('leafletMap');
                    if (!mapContainer) return;

                    // Bharatpur coordinates from the original Google Maps embed
                    const lat = 27.6833;
                    const lng = 84.4239;

                    const map = L.map('leafletMap', {
                        scrollWheelZoom: false,
                        dragging: true,
                        touchZoom: true
                    }).setView([lat, lng], 15);

                    // Realistic Satellite Imagery
                    L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                        attribution: 'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community'
                    }).addTo(map);

                    // Add Hybrid Labels
                    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_only_labels/{z}/{x}/{y}{r}.png', {
                        attribution: '&copy; OpenStreetMap contributors &copy; CARTO',
                        subdomains: 'abcd'
                    }).addTo(map);

                    L.marker([lat, lng]).addTo(map)
                        .bindPopup('<b>Justkleek</b><br>Bharatpur, Nepal')
                        .openPopup();

                    // Enable scroll zoom on click/touch
                    mapContainer.addEventListener('mousedown', () => map.scrollWheelZoom.enable());
                    mapContainer.addEventListener('touchstart', () => map.scrollWheelZoom.enable());

                    // Fix Leaflet tiles issue if container size changes
                    setTimeout(() => {
                        map.invalidateSize();
                    }, 500);
                });
            })();



            // ============================================================================
            // LANGUAGE SWITCHER
            // ============================================================================
            // Handles language selection and persistence (stored in localStorage)
            // ============================================================================
            (function () {
                const langButtons = document.querySelectorAll('.lang-btn');
                let currentLang = localStorage.getItem('selectedLanguage') || 'en';

                function initLanguage() {
                    langButtons.forEach(btn => {
                        const lang = btn.getAttribute('data-lang');

                        if (lang === currentLang) {
                            btn.classList.add('active');
                        }

                        else {
                            btn.classList.remove('active');
                        }
                    });
                }

                function switchLanguage(lang) {
                    currentLang = lang;
                    localStorage.setItem('selectedLanguage', lang);

                    langButtons.forEach(btn => {
                        if (btn.getAttribute('data-lang') === lang) {
                            btn.classList.add('active');
                        }

                        else {
                            btn.classList.remove('active');
                        }
                    });

                    console.log('Language switched to:', lang);
                }

                langButtons.forEach(btn => {
                    btn.addEventListener('click', function (e) {
                        e.preventDefault();
                        const lang = this.getAttribute('data-lang');
                        switchLanguage(lang);
                    });
                });

                initLanguage();
            })();

            /**
         * Update cart count badge in navbar
         */
            function updateCartCount(count) {
                const cartLinks = document.querySelectorAll('.navbar__cart-link');

                cartLinks.forEach(cartLink => {
                    if (cartLink) {
                        const existingBadge = cartLink.querySelector('.cart-count-badge');

                        if (existingBadge) {
                            existingBadge.remove();
                        }

                        if (count > 0) {
                            const badge = document.createElement('span');
                            badge.className = 'cart-count-badge';
                            badge.textContent = count;
                            badge.style.cssText = 'position: absolute; top: -8px; right: -8px; background: #ff4444; color: white; border-radius: 50%; width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; box-shadow: 0 2px 8px rgba(255, 68, 68, 0.4); border: 2px solid white; z-index: 10;';
                            cartLink.style.position = 'relative';
                            cartLink.appendChild(badge);
                        }
                    }
                });
            }

            // Sync cart count on load and after changes
            document.addEventListener('DOMContentLoaded', function () {
                function syncCartCount() {
                    fetch('<?php echo $basePath; ?>/cart.php?action=get_count').then(response => response.json()).then(data => {
                        if (data.success) {
                            updateCartCount(data.cart_count || 0);
                        }
                    }).catch(err => console.error('Cart count sync error:', err));
                }

                syncCartCount();

                // Also update on cart changes in this page
                const originalRenderCart = window.renderCart;

                window.renderCart = function () {
                    if (typeof originalRenderCart === 'function') originalRenderCart();
                    updateCartCount(typeof cart !== 'undefined' ? cart.length : 0);
                }

                    ;
            });

            // ============================================================================
            // FRIEND / FAMILY DELIVERY FEATURE
            // ============================================================================
            (function () {

                // State
                let friendMode = false;            // Is the checkbox checked?
                let friendLat = null;              // Resolved lat for friend
                let friendLng = null;              // Resolved lng for friend
                let friendAddress = '';            // Human-readable address
                let friendDeliveryFee = 0;         // Calculated fee
                let friendDistanceKm = 0;
                let activeFriendTab = 'street';    // 'street' | 'gps'
                let geocodeDebounceTimer = null;
                let nominatimAbort = null;

                const basePath = '<?php echo $basePath; ?>';

                // Elements
                const checkbox        = document.getElementById('orderForFriendCheckbox');
                const checkboxVisual  = document.getElementById('friendCheckboxVisual');
                const checkIcon       = document.getElementById('friendCheckIcon');
                const toggleWrapper   = document.querySelector('.friend-order-toggle-wrapper');
                const panel           = document.getElementById('friendDeliveryPanel');

                // ── Checkbox toggle ──────────────────────────────────────────────
                function toggleFriendMode(enable) {
                    friendMode = enable;
                    checkbox.checked = enable;

                    if (enable) {
                        checkboxVisual.classList.add('checked');
                        checkIcon.style.opacity = '1';
                        checkIcon.style.transform = 'scale(1)';
                        toggleWrapper.classList.add('is-active');
                        panel.style.display = 'block';
                        // Animate in
                        panel.style.opacity = '0';
                        panel.style.transform = 'translateY(-8px)';
                        requestAnimationFrame(() => {
                            panel.style.transition = 'opacity 0.3s ease, transform 0.35s cubic-bezier(0.16,1,0.3,1)';
                            panel.style.opacity = '1';
                            panel.style.transform = 'translateY(0)';
                        });
                    } else {
                        checkboxVisual.classList.remove('checked');
                        checkIcon.style.opacity = '0';
                        checkIcon.style.transform = 'scale(0)';
                        toggleWrapper.classList.remove('is-active');
                        // Animate out
                        panel.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
                        panel.style.opacity = '0';
                        panel.style.transform = 'translateY(-6px)';
                        setTimeout(() => {
                            panel.style.display = 'none';
                            panel.style.transition = '';
                        }, 250);
                        // Reset friend fee & recalc order
                        resetFriendFee();
                        updateOrderSummary();
                    }
                }

                // Click on the label / checkbox area
                const label = document.querySelector('.friend-order-checkbox-label');
                if (label) {
                    label.addEventListener('click', function (e) {
                        e.preventDefault();
                        toggleFriendMode(!friendMode);
                    });
                }

                // ── Delivery Location dropdown ────────────────────────────────────
                window.onFriendDeliveryLocationChange = function () {
                    const sel = document.getElementById('friendDeliveryLocationSelect');
                    const manualWrap = document.getElementById('friendManualLocationWrap');
                    if (!sel) return;
                    if (sel.value === 'Other') {
                        manualWrap && (manualWrap.style.display = 'block');
                    } else {
                        manualWrap && (manualWrap.style.display = 'none');
                    }
                    hideFriendResult(); // clear previous result when area changes
                };

                // ── Calculate fee from form fields ────────────────────────────────
                window.calculateFriendFeeFromForm = async function () {
                    const sel    = document.getElementById('friendDeliveryLocationSelect');
                    const textEl = document.getElementById('friendStreetLocationText');
                    const manualEl = document.getElementById('friendManualLocation');

                    // Validate delivery location chosen
                    if (!sel || !sel.value) {
                        showFriendError('Please select a Delivery Location area first.');
                        return;
                    }
                    const areaName = sel.value === 'Other'
                        ? (manualEl?.value.trim() || 'Other')
                        : sel.value;

                    // Parse lat/lng from the text field
                    let inputVal = textEl?.value.trim() || '';
                    if (!inputVal) {
                        showFriendError('Please enter the street location or GPS coordinates for the recipient.');
                        return;
                    }

                    hideFriendResult(); // Hide any existing errors

                    let lat = null, lng = null;

                    // Format 1: 27.6833, 84.4239 or 27.6833 84.4239
                    const coordMatch = inputVal.match(/(-?\d+\.\d+)[,\s]+(-?\d+\.\d+)/);
                    if (coordMatch) {
                        lat = parseFloat(coordMatch[1]);
                        lng = parseFloat(coordMatch[2]);
                    } 
                    // Format 2: Google Maps URL w/ @lat,lng
                    else if (inputVal.includes('@')) {
                        const urlMatch = inputVal.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/);
                        if (urlMatch) {
                            lat = parseFloat(urlMatch[1]);
                            lng = parseFloat(urlMatch[2]);
                        }
                    }

                    if (lat === null || isNaN(lat) || lng === null || isNaN(lng)) {
                        // If we still don't have coordinates, let's treat it as an address and geocode it
                        showFriendSpinner();
                        try {
                            const url = `https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(inputVal)}&limit=1&countrycodes=np&addressdetails=1`;
                            const res = await fetch(url, { headers: { 'Accept-Language': 'en' } });
                            const results = await res.json();
                            if (!results || results.length === 0) {
                                hideFriendSpinner();
                                showFriendError("We couldn't find coordinates for that location. Please paste an exact Google Maps link or latitude/longitude.");
                                return;
                            }
                            lat = parseFloat(results[0].lat);
                            lng = parseFloat(results[0].lon);
                            inputVal = results[0].display_name;
                        } catch(e) {
                            hideFriendSpinner();
                            showFriendError('Network error while searching for location.');
                            return;
                        }
                    }

                    friendLat     = lat;
                    friendLng     = lng;
                    friendAddress = inputVal;
                    friendDeliveryLocation = areaName;

                    showFriendSpinner();
                    await calculateFriendFee();
                };

                // ── Core: call calc_delivery API ──────────────────────────────────
                async function calculateFriendFee() {
                    if (friendLat === null || friendLng === null) return;
                    showFriendSpinner();
                    try {
                        const res = await fetch(`${basePath}/api/location/calc_delivery.php`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ lat: friendLat, lng: friendLng })
                        });
                        const data = await res.json();
                        hideFriendSpinner();

                        if (data.success) {
                            friendDeliveryFee = parseFloat(data.delivery_fee) || 0;
                            friendDistanceKm  = parseFloat(data.distance_km)  || 0;

                            // PERMANENT RESTRICTION: Allow state to persist even if too far,
                            // so the 'Order Now' click handler can catch it and show the professional modal.
                            if (friendDistanceKm > DELIVERY_MAX_DISTANCE) {
                                // Update global fee/dist so Order Now text reflects it
                                currentDeliveryFee = friendDeliveryFee;
                                deliveryDistanceKm = friendDistanceKm;
                                patchOrderTotal();
                                return;
                            }

                            // Override global delivery fee with friend's fee
                            currentDeliveryFee  = friendDeliveryFee;
                            deliveryDistanceKm  = friendDistanceKm;
                            // Recalculate order total (skip location re-check by patching UI directly)
                            patchOrderTotal();
                        } else {
                            resetFriendFee();
                            showFriendError(data.error || 'Unable to calculate delivery fee. The location may be out of our delivery zone.');
                        }
                    } catch (e) {
                        hideFriendSpinner();
                        showFriendError('Failed to calculate delivery fee. Please try again.');
                    }
                }

                // ── Patch order summary UI for friend fee ────────────────────────
                function patchOrderTotal() {
                    const parsePrice = (p) => {
                        if (typeof p === 'string') return parseFloat(p.replace(/Rs\./g, '').replace(/[^\d.]/g, '')) || 0;
                        return parseFloat(p) || 0;
                    };
                    const subtotal = cart.reduce((sum, item) => sum + (parsePrice(item.price) * item.quantity), 0);
                    const total    = subtotal + currentDeliveryFee;

                    const deliveryFeeRow = document.getElementById('deliveryFeeRow');
                    const orderDeliveryFeeText = document.getElementById('orderDeliveryFeeText');
                    const orderTotalEl = document.getElementById('orderTotal');

                    if (deliveryFeeRow)  deliveryFeeRow.style.display = 'flex';
                    if (orderDeliveryFeeText) orderDeliveryFeeText.textContent = `Rs. ${currentDeliveryFee.toFixed(2)}`;
                    if (orderTotalEl)    orderTotalEl.textContent = `Rs. ${total.toFixed(2)}`;

                    // Enable order button
                    // Enable order button if within distance (Logic updated to keep enabled for modal click)
                    const orderNowBtn = document.getElementById('orderNowBtn');
                    if (orderNowBtn && cart.length > 0) orderNowBtn.disabled = false;
                }

                function resetFriendFee() {
                    friendDeliveryFee = 0;
                    friendDistanceKm  = 0;
                    friendLat  = null;
                    friendLng  = null;
                    friendAddress = '';
                    hideFriendResult();
                }

                // ── UI helpers ───────────────────────────────────────────────────
                function showFriendSpinner() {
                    const s = document.getElementById('friendFeeSpinner');
                    const e = document.getElementById('friendFeeError');
                    if (s) s.style.display = 'block';
                    if (e) e.style.display = 'none';
                }
                function hideFriendSpinner() {
                    const s = document.getElementById('friendFeeSpinner');
                    if (s) s.style.display = 'none';
                }
                function hideFriendResult() {
                    const e = document.getElementById('friendFeeError');
                    const s = document.getElementById('friendFeeSpinner');
                    if (e) e.style.display = 'none';
                    if (s) s.style.display = 'none';
                }
                function showFriendError(msg) {
                    const e = document.getElementById('friendFeeError');
                    if (!e) return;
                    e.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg> ${msg}`;
                    e.style.display = 'flex';
                }

                // ── Hook into order placement ─────────────────────────────────────
                // Intercept the "Confirm & Place Order" button to inject friend data
                document.addEventListener('DOMContentLoaded', function () {
                    const confirmBtn = document.getElementById('confirmPaymentBtn');
                    if (!confirmBtn) return;

                    // Wrap original click via event capture to inject friend address coords before order is built
                    document.getElementById('paymentMethodModal')?.addEventListener('click', function (e) {
                        if (!e.target.closest('#confirmPaymentBtn')) return;
                        if (!friendMode || friendLat === null) return;

                        // Temporarily override the hidden location inputs so the existing order-build logic picks them up
                        const latEl  = document.getElementById('location_lat');
                        const lngEl  = document.getElementById('location_lng');
                        const strEl  = document.getElementById('street_location');
                        const delEl  = document.getElementById('delivery_location');

                        if (latEl)  latEl.value  = friendLat;
                        if (lngEl)  lngEl.value  = friendLng;
                        if (strEl)  strEl.value  = friendAddress; // The full geocoded street name
                        if (delEl)  delEl.value  = friendDeliveryLocation || 'Other'; // From the dropdown
                    }, true /* capture — runs before the confirm handler */);
                });

                // Expose state for order-note validation
                window.getFriendDeliveryState = function () {
                    return { active: friendMode, lat: friendLat, lng: friendLng, address: friendAddress, fee: friendDeliveryFee, distance: friendDistanceKm };
                };

            })();
            // ── END FRIEND / FAMILY DELIVERY FEATURE ──────────────────────────────
        </script>
        <!-- Distance Limit Modal -->
        <div class="distance-limit-modal" id="distanceLimitModal">
            <div class="distance-modal-backdrop" onclick="closeDistanceLimitModal()"></div>
            <div class="distance-modal-content">
                <div class="distance-modal-icon">
                    <div class="icon-pulse-apology"></div>
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#FF5252" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                </div>
                <h2 class="distance-modal-title">We're Truly Sorry!</h2>
                <p class="distance-modal-text">
                    Our current delivery range is limited to <strong><?php echo floatval(getDeliverySettings()['max_distance'] ?? 25); ?> km</strong> to ensure your food stays fresh and hot. 
                    Unfortunately, your location is too far for us to reach at this time.
                </p>
                <div class="distance-modal-note">
                    We'd love to serve you if you're ever visiting somewhere closer to our kitchen!
                </div>
                <button class="distance-modal-btn" onclick="closeDistanceLimitModal()">
                    I Understand
                </button>
            </div>
        </div>

        <!-- Delivery Scooter Loading Modal -->
        <div class="loading-modal" id="loadingModal">
            <div class="loading-backdrop"></div>
            <div class="loading-content">
                <div class="scooter-container">
                    <!-- Modern 3D-ish Scooter SVG -->
                    <svg class="scooter-svg" viewBox="0 0 120 80" width="140" height="90">
                        <!-- Wind/Speed lines -->
                        <path class="wind-line wind-line-1" d="M 0 30 L 25 30" stroke="#CBD5E1" stroke-width="3"
                            stroke-linecap="round" fill="none" />
                        <path class="wind-line wind-line-2" d="M 10 50 L 40 50" stroke="#CBD5E1" stroke-width="3"
                            stroke-linecap="round" fill="none" />
                        <path class="wind-line wind-line-3" d="M -5 65 L 15 65" stroke="#CBD5E1" stroke-width="3"
                            stroke-linecap="round" fill="none" />

                        <g class="scooter-body-group">
                            <!-- Delivery Box -->
                            <rect x="35" y="25" width="28" height="28" rx="4" fill="#FF5252" />
                            <path d="M 35 35 L 63 35" stroke="#fff" stroke-width="2" opacity="0.4" />
                            <circle cx="49" cy="35" r="4" fill="#fff" opacity="0.8" />

                            <!-- Main Scooter Body -->
                            <path class="scooter-chassis" d="M 30 55 L 75 55 L 85 40 L 95 40 L 100 65 L 30 65 Z"
                                fill="#F97316" />

                            <!-- Front Post & Handlebars -->
                            <path d="M 80 55 L 90 20" stroke="#F97316" stroke-width="6" stroke-linecap="round" />
                            <circle cx="90" cy="20" r="4" fill="#334155" />
                            <path d="M 85 20 L 95 20" stroke="#1E293B" stroke-width="4" stroke-linecap="round" />

                            <!-- Headlight -->
                            <path d="M 90 35 L 100 35 L 95 45 Z" fill="#FFD166" />
                            <circle cx="95" cy="40" r="6" fill="#FFFBEB" class="headlight-beam" />

                            <!-- Seat -->
                            <path d="M 60 38 L 80 38 L 78 45 L 60 45 Z" fill="#334155" />

                            <!-- Wheels -->
                            <g class="wheel back-wheel">
                                <circle cx="45" cy="65" r="12" fill="#1E293B" />
                                <circle cx="45" cy="65" r="6" fill="#F8FAFC" />
                                <circle cx="45" cy="65" r="2" fill="#94A3B8" />
                            </g>

                            <g class="wheel front-wheel">
                                <circle cx="95" cy="65" r="12" fill="#1E293B" />
                                <circle cx="95" cy="65" r="6" fill="#F8FAFC" />
                                <circle cx="95" cy="65" r="2" fill="#94A3B8" />
                            </g>
                        </g>

                        <!-- Ground / Road line -->
                        <path class="road-line" d="M 20 77 L 110 77" stroke="#E2E8F0" stroke-width="3"
                            stroke-linecap="round" />
                    </svg>
                </div>
                <h3 class="loading-title">Preparing Your Order...</h3>
                <p class="loading-text">Our chefs are busy. Rider is revving up!</p>
                <div class="bouncing-dots">
                    <span class="dot"></span>
                    <span class="dot"></span>
                    <span class="dot"></span>
                </div>
            </div>
        </div>

        <style>
            /* Scooter Delivery Loading Modal Styles */
            .loading-modal {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 10001;
                /* Above payment modal */
                display: flex;
                justify-content: center;
                align-items: center;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.6s cubic-bezier(0.16, 1, 0.3, 1), visibility 0.6s;
            }

            .loading-modal.active {
                opacity: 1;
                visibility: visible;
            }

            .loading-backdrop {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(15, 23, 42, 0.85);
                /* Slate 900 */
                backdrop-filter: blur(12px);
                -webkit-backdrop-filter: blur(12px);
            }

            .loading-content {
                position: relative;
                background: rgba(255, 255, 255, 0.95);
                backdrop-filter: blur(20px);
                -webkit-backdrop-filter: blur(20px);
                padding: 40px 40px 45px 40px;
                border-radius: 32px;
                text-align: center;
                width: 90%;
                max-width: 420px;
                box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 0.3), inset 0 0 0 1px rgba(255, 255, 255, 0.8);
                transform: scale(0.85) translateY(20px);
                transition: transform 0.8s cubic-bezier(0.34, 1.56, 0.64, 1);
            }

            .loading-modal.active .loading-content {
                transform: scale(1) translateY(0);
            }

            /* Scooter Animation */
            .scooter-container {
                position: relative;
                width: 100%;
                height: 90px;
                margin: 0 auto 20px auto;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .scooter-svg {
                overflow: visible;
            }

            .scooter-body-group {
                animation: scooterBounce 0.5s ease-in-out infinite alternate;
                transform-origin: 65px 65px;
            }

            .wheel {
                animation: wheelSpin 0.4s linear infinite;
                transform-origin: center;
                transform-box: fill-box;
            }

            .wind-line {
                stroke-dasharray: 40;
                stroke-dashoffset: 40;
                animation: windBlow 1s linear infinite;
            }

            .wind-line-1 {
                animation-duration: 0.8s;
                animation-delay: 0.1s;
            }

            .wind-line-2 {
                animation-duration: 1.2s;
                animation-delay: 0.4s;
            }

            .wind-line-3 {
                animation-duration: 0.9s;
                animation-delay: 0.2s;
            }

            .road-line {
                stroke-dasharray: 20 10;
                animation: roadMove 0.5s linear infinite;
            }

            .headlight-beam {
                animation: headlightPulse 1s ease-in-out infinite alternate;
            }

            @keyframes scooterBounce {
                0% {
                    transform: translateY(0) rotate(-1deg);
                }

                100% {
                    transform: translateY(-3px) rotate(1deg);
                }
            }

            @keyframes wheelSpin {
                0% {
                    transform: rotate(0deg);
                }

                100% {
                    transform: rotate(-360deg);
                }
            }

            @keyframes windBlow {
                0% {
                    stroke-dashoffset: -40;
                    opacity: 0;
                }

                20% {
                    opacity: 1;
                }

                80% {
                    opacity: 1;
                }

                100% {
                    stroke-dashoffset: 40;
                    opacity: 0;
                }
            }

            @keyframes roadMove {
                0% {
                    stroke-dashoffset: 0;
                }

                100% {
                    stroke-dashoffset: 30;
                }
            }

            @keyframes headlightPulse {
                0% {
                    opacity: 0.6;
                    filter: drop-shadow(0 0 2px #FFFBEB);
                }

                100% {
                    opacity: 1;
                    filter: drop-shadow(0 0 8px #FFFBEB);
                }
            }

            /* Texts & Dots */
            .loading-title {
                font-family: 'Plus Jakarta Sans', sans-serif;
                font-size: 22px;
                font-weight: 800;
                color: #0f172a;
                margin: 0 0 10px 0;
                letter-spacing: -0.5px;
            }

            .loading-text {
                font-family: 'Inter', sans-serif;
                font-size: 15px;
                font-weight: 500;
                color: #64748b;
                margin: 0 0 20px 0;
                line-height: 1.5;
            }

            .bouncing-dots {
                display: flex;
                flex-direction: row;
                justify-content: center;
                gap: 8px;
            }

            .bouncing-dots .dot {
                width: 8px;
                height: 8px;
                background-color: #f97316;
                border-radius: 50%;
                animation: bounceDot 1.4s infinite ease-in-out both;
            }

            .bouncing-dots .dot:nth-child(1) {
                animation-delay: -0.32s;
            }

            .bouncing-dots .dot:nth-child(2) {
                animation-delay: -0.16s;
            }

            @keyframes bounceDot {

                0%,
                80%,
                100% {
                    transform: scale(0);
                    opacity: 0.3;
                }

                40% {
                    transform: scale(1);
                    opacity: 1;
                }
            }

            /* Distance Limit Modal Styles */
            .distance-limit-modal {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                z-index: 10002;
                display: flex;
                justify-content: center;
                align-items: center;
                opacity: 0;
                visibility: hidden;
                transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            }

            .distance-limit-modal.active {
                opacity: 1;
                visibility: visible;
            }

            .distance-modal-backdrop {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(15, 23, 42, 0.75);
                backdrop-filter: blur(8px);
                -webkit-backdrop-filter: blur(8px);
            }

            .distance-modal-content {
                position: relative;
                background: white;
                width: 90%;
                max-width: 400px;
                padding: 40px 30px;
                border-radius: 32px;
                text-align: center;
                box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 0.3);
                transform: scale(0.9) translateY(20px);
                transition: transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            }

            .distance-limit-modal.active .distance-modal-content {
                transform: scale(1) translateY(0);
            }

            .distance-modal-icon {
                width: 80px;
                height: 80px;
                background: #fff1f2;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0 auto 24px;
                position: relative;
            }

            .icon-pulse-apology {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                border-radius: 50%;
                background: #FF5252;
                opacity: 0.15;
                animation: pulseApology 2s infinite;
            }

            @keyframes pulseApology {
                0% { transform: scale(1); opacity: 0.15; }
                70% { transform: scale(1.5); opacity: 0; }
                100% { transform: scale(1.5); opacity: 0; }
            }

            .distance-modal-title {
                font-family: 'Plus Jakarta Sans', sans-serif;
                font-size: 24px;
                font-weight: 800;
                color: #1e293b;
                margin-bottom: 12px;
            }

            .distance-modal-text {
                font-family: 'Inter', sans-serif;
                font-size: 15px;
                color: #64748b;
                line-height: 1.6;
                margin-bottom: 20px;
            }

            .distance-modal-note {
                background: #f8fafc;
                padding: 12px 16px;
                border-radius: 12px;
                font-size: 13px;
                color: #94a3b8;
                font-style: italic;
                margin-bottom: 24px;
            }

            .distance-modal-btn {
                background: #1e293b;
                color: white;
                border: none;
                padding: 16px 32px;
                border-radius: 16px;
                font-size: 16px;
                font-weight: 700;
                width: 100%;
                cursor: pointer;
                transition: all 0.3s ease;
                font-family: 'Plus Jakarta Sans', sans-serif;
            }

            .distance-modal-btn:hover {
                background: #0f172a;
                transform: translateY(-2px);
            }
        </style>
        <!-- Email Verification Required Modal -->
        <?php if ($isLoggedIn && !$isVerified): ?>
                <?php require_once __DIR__ . '/includes/verification_modal.php'; ?>
                <script> // Auto-show modal

                    document.addEventListener('DOMContentLoaded', function () {
                        if (typeof showVerificationModal === 'function') {
                            showVerificationModal();
                        }
                    });
                </script><?php endif; ?>
        <script src="<?php echo $basePath; ?>/assets/js/dv_mobile_nav.js" defer></script>


        <script>
            // Geolocation Script
            document.addEventListener('DOMContentLoaded', function () {
                const locateBtns = document.querySelectorAll('.use-current-location-btn');
                locateBtns.forEach(btn => {
                    btn.addEventListener('click', function () {
                        const container = this.parentElement;

                        if (!navigator.geolocation) {
                            console.warn("Geolocation is not supported on this device.");
                            return;
                        }

                        const originalText = this.textContent;
                        this.textContent = "Getting...";
                        this.disabled = true;

                        navigator.geolocation.getCurrentPosition(
                            async function (position) {
                                btn.textContent = originalText;
                                btn.disabled = false;

                                const lat = position.coords.latitude;
                                const lng = position.coords.longitude;

                                // Field mapping
                                const latInput = document.querySelector('#location_lat, #editLocationLat, #delivery_lat');
                                const lngInput = document.querySelector('#location_lng, #editLocationLng, #delivery_lng');
                                const addressInput = document.querySelector('#street_location, #editStreetLocation');

                                if (latInput) latInput.value = lat;
                                if (lngInput) lngInput.value = lng;

                                if (addressInput) {
                                    addressInput.value = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                                }

                                const displayAddress = document.querySelector('.delivery-address-text');
                                if (displayAddress) {
                                    displayAddress.textContent = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                                }

                                if (typeof updateOrderSummary === 'function') {
                                    updateOrderSummary();
                                }
                            },
                            function (error) {
                                btn.textContent = originalText;
                                btn.disabled = false;
                                console.warn("Could not get location:", error);
                            },
                            {
                                enableHighAccuracy: true,
                                timeout: 10000,
                                maximumAge: 0
                            }
                        );
                    });
                });
            });
        </script>
</body>

</html>