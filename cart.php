<?php
// Security initialization (must be first)
require_once __DIR__ . '/app/functions/security_init.php';

require_once __DIR__ . '/app/functions/auth.php';

$basePath = getBasePath();


if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_count') {
    header('Content-Type: application/json');
    $count = isset($_SESSION['cart']) && is_array($_SESSION['cart']) ? count($_SESSION['cart']) : 0;
    // Release session lock immediately (important for parallel requests/navigation).
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    echo json_encode(['success' => true, 'cart_count' => $count]);
    exit;
}

// Load cart from database if user is logged in
if (isUserLoggedIn()) {
    require_once __DIR__ . '/config/db.php';
    $user = getCurrentUser();
    $userId = $user['id'] ?? null;

    if ($userId) {
        try {
            if (isset($pdo) && $pdo !== null) {
                // Load cart items from database
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

                // Merge database cart with session cart (database takes priority)
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
            error_log("Cart load error: " . $e->getMessage());
            // Fallback to session cart
            if (!isset($_SESSION['cart'])) {
                $_SESSION['cart'] = [];
            }
        }
    }
} else {
    // Initialize cart if not exists (for guests)
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
}

// Function to save cart to database
function saveCartToDatabase($cart, $userId)
{
    global $pdo;
    if (!$pdo || !$userId)
        return false;

    try {
        if (isset($pdo) && $pdo !== null) {
            // Start transaction
            $pdo->beginTransaction();

            // Delete existing cart items for this user
            $deleteStmt = $pdo->prepare("DELETE FROM cart_items WHERE user_id = ?");
            $deleteStmt->execute([$userId]);

            // Insert current cart items
            if (!empty($cart)) {
                $insertStmt = $pdo->prepare("
                    INSERT INTO cart_items (user_id, item_id, item_name, item_description, quantity, unit_price, item_image)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                foreach ($cart as $item) {
                    $insertStmt->execute([
                        $userId,
                        $item['id'] ?? uniqid('item_', true),
                        $item['name'] ?? '',
                        $item['description'] ?? '',
                        intval($item['quantity'] ?? 1),
                        floatval($item['price'] ?? 0),
                        $item['image'] ?? 'assets/plate.png'
                    ]);
                }
            }

            $pdo->commit();
            return true;
        }
    } catch (Throwable $e) {
        error_log("Cart save error: " . $e->getMessage());
        try {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $rollbackError) {
            // Ignore rollback errors
        }
        return false;
    }
    return false;
}

// Check if this is an AJAX request (API call)
$isAjaxRequest = (
    $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])
) || (
    $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])
) || (
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
);

// Handle AJAX/API requests
if ($isAjaxRequest) {
    // For AJAX requests, check authentication
    if (!isUserLoggedIn()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Please log in to continue', 'requires_login' => true]);
        exit;
    }

    if (!isUserVerified()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Please verify your email to place orders', 'requires_verification' => true]);
        exit;
    }
} else {
    // For regular page loads, require login (but not verification - handled client-side)
    requireUserLogin();
    // Verification check is handled client-side to show popup instead of redirect
}

// Get user verification status for client-side check
$user = getCurrentUser();
$isVerified = $user && ($user['is_verified'] ?? 0);

// Handle AJAX/API requests
if ($isAjaxRequest) {
    // Set content type to JSON for all API responses
    header('Content-Type: application/json');

    // Handle GET requests for cart count
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_count') {
        echo json_encode([
            'success' => true,
            'cart_count' => count($_SESSION['cart'])
        ]);
        exit;
    }

    // Handle cart operations via AJAX
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

        if ($_POST['action'] === 'add') {
            // Validate required fields
            if (!isset($_POST['name']) || !isset($_POST['price'])) {
                echo json_encode(['success' => false, 'error' => 'Missing required fields']);
                exit;
            }

            // Get the actual price from database (don't trust client-side price)
            $itemName = htmlspecialchars(trim($_POST['name']));
            $actualPrice = 0;

            try {
                if (isset($pdo) && $pdo !== null) {
                    // Check if menu_items table exists
                    $tableCheck = $pdo->query("SHOW TABLES LIKE 'menu_items'")->fetch();

                    if ($tableCheck) {
                        // Fetch price from database for the selected branch
                        $branchId = $_SESSION['customer_branch_id'] ?? 1;
                        $stmt = $pdo->prepare("SELECT price FROM menu_items WHERE item_name = ? AND restaurant_id = ? AND is_active = 1 LIMIT 1");
                        $stmt->execute([$itemName, $branchId]);
                        $menuItem = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($menuItem) {
                            $actualPrice = floatval($menuItem['price']);
                        } else {
                            // Fallback to client price if item not found in database
                            $actualPrice = floatval(preg_replace('/[^\d.]/', '', str_replace('Rs.', '', $_POST['price'] ?? 0)));
                        }
                    } else {
                        // Fallback to client price if table doesn't exist
                        $actualPrice = floatval(preg_replace('/[^\d.]/', '', str_replace('Rs.', '', $_POST['price'] ?? 0)));
                    }
                } else {
                    $actualPrice = floatval(preg_replace('/[^\d.]/', '', str_replace('Rs.', '', $_POST['price'] ?? 0)));
                }
            } catch (Throwable $e) {
                // Fallback to client price on error
                $actualPrice = floatval(preg_replace('/[^\d.]/', '', str_replace('Rs.', '', $_POST['price'] ?? 0)));
            }

            // Extract and sanitize request data
            $item = [
                'id' => isset($_POST['id']) ? $_POST['id'] : uniqid('item_', true),
                'name' => $itemName,
                'description' => isset($_POST['description']) ? htmlspecialchars(trim($_POST['description'])) : '',
                'price' => $actualPrice,
                'quantity' => isset($_POST['quantity']) ? intval($_POST['quantity']) : 1,
                'image' => isset($_POST['image']) ? htmlspecialchars(trim($_POST['image'])) : 'assets/plate.png'
            ];

            // ---------------------------------------------------------
            // STOCK VALIDATION (MODERN & SECURE)
            // ---------------------------------------------------------
            try {
                // Check current stock in database for the selected branch
                $branchId = $_SESSION['customer_branch_id'] ?? 1;
                $stockStmt = $pdo->prepare("
                    SELECT m.stock_count, m.track_stock, c.category_name 
                    FROM menu_items m 
                    JOIN menu_categories c ON m.category_id = c.id 
                    WHERE m.item_name = ? AND m.restaurant_id = ?
                ");
                $stockStmt->execute([$itemName, $branchId]);
                $stockData = $stockStmt->fetch(PDO::FETCH_ASSOC);

                if ($stockData) {
                    $availableStock = (int) $stockData['stock_count'];

                    // Define allowed categories for Stock Management 
                    $allowed_stock_categories = ['Groceries', 'Justkleek Drinks', 'JustKleek Drinks', 'Soft drinks', 'Soft Drinks', 'Grocery', 'Grocerys', 'Beer Selection'];

                    // Check if category is tracked
                    $categoryName = $stockData['category_name'];
                    $shouldTrackStock = in_array($categoryName, $allowed_stock_categories);

                    if ($shouldTrackStock) {
                        // Calculate total requested including existing cart items
                        $totalRequested = $item['quantity'];

                        // Check if item exists in current session cart to sum up
                        foreach ($_SESSION['cart'] as $cartItem) {
                            if ($cartItem['name'] === $item['name'] && $cartItem['price'] === $item['price']) {
                                $totalRequested += $cartItem['quantity'];
                                break;
                            }
                        }

                        // Validation: Only if tracking is enabled for this category
                        if ($totalRequested > $availableStock) {
                            echo json_encode([
                                'success' => false,
                                'error' => "Sorry, we only have {$availableStock} of '{$itemName}' left in stock."
                            ]);
                            exit;
                        }
                    }
                }
            } catch (Exception $e) {
                // Log stock check error but allow adding (fallback)
                error_log("Stock check error: " . $e->getMessage());
            }
            // ---------------------------------------------------------

            // Check if item already exists in cart (by name and price)
            $itemExists = false;
            $existingIndex = -1;
            foreach ($_SESSION['cart'] as $index => $cartItem) {
                if ($cartItem['name'] === $item['name'] && $cartItem['price'] === $item['price']) {
                    $itemExists = true;
                    $existingIndex = $index;
                    break;
                }
            }

            if ($itemExists) {
                // Update quantity of existing item
                $_SESSION['cart'][$existingIndex]['quantity'] += $item['quantity'];

                // Release session lock immediately
                session_write_close();

                // Save to database if user is logged in
                if (isUserLoggedIn()) {
                    $user = getCurrentUser();
                    if ($user && isset($user['id'])) {
                        saveCartToDatabase($_SESSION['cart'], $user['id']);
                    }
                }

                echo json_encode([
                    'success' => true,
                    'cart_count' => count($_SESSION['cart']),
                    'message' => 'Item quantity updated in cart',
                    'action' => 'updated'
                ]);
            } else {
                // Add new item to cart
                $_SESSION['cart'][] = $item;

                // Release session lock immediately
                session_write_close();

                // Save to database if user is logged in
                if (isUserLoggedIn()) {
                    $user = getCurrentUser();
                    if ($user && isset($user['id'])) {
                        saveCartToDatabase($_SESSION['cart'], $user['id']);
                    }
                }

                echo json_encode([
                    'success' => true,
                    'cart_count' => count($_SESSION['cart']),
                    'message' => 'Item added to cart',
                    'action' => 'added'
                ]);
            }
            exit;
        }

        if ($_POST['action'] === 'update') {
            $index = intval($_POST['index']);
            $quantity = intval($_POST['quantity']);

            // Stock Validation
            if (isset($_SESSION['cart'][$index])) {
                $cartItem = $_SESSION['cart'][$index];
                try {
                    $branchId = $_SESSION['customer_branch_id'] ?? 1;
                    $stockStmt = $pdo->prepare("
                        SELECT m.stock_count, c.category_name 
                        FROM menu_items m
                        JOIN menu_categories c ON m.category_id = c.id
                        WHERE m.item_name = ? AND m.restaurant_id = ?
                    ");
                    $stockStmt->execute([$cartItem['name'], $branchId]);
                    $stockData = $stockStmt->fetch(PDO::FETCH_ASSOC);

                    if ($stockData) {
                        $stockCount = (int) $stockData['stock_count'];
                        $categoryName = $stockData['category_name'];

                        // Define allowed categories for Stock Management 
                        $allowed_stock_categories = ['Groceries', 'Justkleek Drinks', 'JustKleek Drinks', 'Soft drinks', 'Soft Drinks', 'Grocery', 'Grocerys', 'Beer Selection'];

                        $shouldTrackStock = in_array($categoryName, $allowed_stock_categories);

                        if ($shouldTrackStock) {
                            if ($quantity > $stockCount) {
                                echo json_encode([
                                    'success' => false,
                                    'error' => "Sorry, only {$stockCount} available.",
                                    'available_stock' => $stockCount,
                                    'reset_qty' => $stockCount // Tell frontend to reset to max
                                ]);
                                exit;
                            }
                        }
                    }
                } catch (Exception $e) {
                }
            }

            if (isset($_SESSION['cart'][$index])) {
                if ($quantity <= 0) {
                    unset($_SESSION['cart'][$index]);
                    $_SESSION['cart'] = array_values($_SESSION['cart']);

                    // Release session lock immediately
                    session_write_close();

                    // Save to database if user is logged in
                    if (isUserLoggedIn()) {
                        $user = getCurrentUser();
                        if ($user && isset($user['id'])) {
                            saveCartToDatabase($_SESSION['cart'], $user['id']);
                        }
                    }

                    echo json_encode([
                        'success' => true,
                        'cart_count' => count($_SESSION['cart']),
                        'message' => 'Item removed from cart'
                    ]);
                } else {
                    $_SESSION['cart'][$index]['quantity'] = $quantity;

                    // Release session lock immediately
                    session_write_close();

                    // Save to database if user is logged in
                    if (isUserLoggedIn()) {
                        $user = getCurrentUser();
                        if ($user && isset($user['id'])) {
                            saveCartToDatabase($_SESSION['cart'], $user['id']);
                        }
                    }

                    echo json_encode([
                        'success' => true,
                        'cart_count' => count($_SESSION['cart']),
                        'message' => 'Item quantity updated'
                    ]);
                }
            } else {
                echo json_encode(['success' => false, 'error' => 'Item not found in cart']);
            }
            exit;
        }

        if ($_POST['action'] === 'remove') {
            $index = intval($_POST['index']);
            if (isset($_SESSION['cart'][$index])) {
                unset($_SESSION['cart'][$index]);
                $_SESSION['cart'] = array_values($_SESSION['cart']);

                // Release session lock immediately
                session_write_close();

                // Save to database if user is logged in
                if (isUserLoggedIn()) {
                    $user = getCurrentUser();
                    if ($user && isset($user['id'])) {
                        saveCartToDatabase($_SESSION['cart'], $user['id']);
                    }
                }

                echo json_encode([
                    'success' => true,
                    'cart_count' => count($_SESSION['cart']),
                    'message' => 'Item removed from cart'
                ]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Item not found in cart']);
            }
            exit;
        }

        if ($_POST['action'] === 'clear') {
            $_SESSION['cart'] = [];

            // Release session lock immediately
            session_write_close();

            // Clear from database if user is logged in
            if (isUserLoggedIn()) {
                $user = getCurrentUser();
                if ($user && isset($user['id'])) {
                    saveCartToDatabase([], $user['id']); // Empty array clears cart
                }
            }

            echo json_encode([
                'success' => true,
                'cart_count' => 0,
                'message' => 'Cart cleared'
            ]);
            exit;
        }

        if ($_POST['action'] === 'get') {
            // Return current cart contents
            $cartTotal = 0;
            foreach ($_SESSION['cart'] as $item) {
                $cartTotal += $item['price'] * $item['quantity'];
            }

            echo json_encode([
                'success' => true,
                'cart' => $_SESSION['cart'],
                'cart_count' => count($_SESSION['cart']),
                'cart_total' => $cartTotal
            ]);
            exit;
        }

        // Unknown action
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
    }

    // Handle GET request - return cart info
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $cartTotal = 0;
        foreach ($_SESSION['cart'] as $item) {
            $cartTotal += $item['price'] * $item['quantity'];
        }

        echo json_encode([
            'success' => true,
            'cart' => $_SESSION['cart'],
            'cart_count' => count($_SESSION['cart']),
            'cart_total' => $cartTotal
        ]);
        exit;
    }

    // Invalid request method
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// If not an AJAX request, display the cart page
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
        content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Cart | JustKleek</title>
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/style.css?v=1.0.1">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/cart.css?v=1.0.1">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/animations.css?v=1.0.1">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/assets/css/dv_mobile_nav_v3.css?v=<?php echo time(); ?>">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link
        href="https://fonts.bunny.net/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Inter:wght@300;400;500;600;700&family=Montserrat:wght@300;400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <script src="<?php echo $basePath; ?>/js/prevent-zoom.js"></script>
    <script src="<?php echo $basePath; ?>/js/click-sound.js"></script>
    <style>
        /* Modern Typography Overrides */
        body,
        html,
        button,
        input,
        .cart-title,
        .section-title,
        .cart-item-name,
        .cart-item-total-price,
        .summary-row,
        .cart-qty-value,
        .proceed-to-checkout-btn,
        .back-to-menu-btn span,
        .remove-confirm-title,
        .remove-confirm-message,
        .remove-confirm-btn,
        .empty-cart-title,
        .empty-cart-text {
            font-family: 'Plus Jakarta Sans', 'Inter', -apple-system, sans-serif !important;
            text-rendering: optimizeLegibility !important;
            -webkit-font-smoothing: antialiased !important;
        }

        /* Desktop Refined Sizing */
        .cart-title {
            font-size: 36px !important;
            font-weight: 800 !important;
            letter-spacing: -1px !important;
        }

        .back-to-menu-btn {
            padding: 10px 20px !important;
            font-size: 13.5px !important;
            border-radius: 12px !important;
        }

        .cart-item-name {
            font-size: 15.5px !important;
            font-weight: 700 !important;
            color: #1a1a1a !important;
        }

        .cart-item-total-price {
            font-size: 18.5px !important;
            font-weight: 800 !important;
        }

        .cart-qty-value {
            font-size: 15px !important;
            font-weight: 800 !important;
        }

        .summary-row {
            font-size: 14.5px !important;
        }

        .summary-row.total-row {
            font-size: 20px !important;
        }

        .summary-row.total-row .price {
            font-size: 24px !important;
        }

        .proceed-to-checkout-btn {
            font-size: 14.5px !important;
            padding: 15px 24px !important;
            border-radius: 12px !important;
        }

        .section-title {
            font-size: 22px !important;
            font-weight: 700 !important;
        }

        .empty-cart-title {
            font-size: 26px !important;
            font-weight: 800 !important;
        }

        .empty-cart-text {
            font-size: 14px !important;
            color: #71717a !important;
        }

        .remove-confirm-title {
            font-size: 26px !important;
            font-weight: 800 !important;
        }

        .remove-confirm-message {
            font-size: 14.5px !important;
            color: #52525b !important;
        }

        .remove-confirm-btn {
            font-size: 14px !important;
            padding: 14px 24px !important;
        }

        /* Quality buttons refinement */
        .cart-qty-btn {
            width: 34px !important;
            height: 34px !important;
            font-size: 18px !important;
            border-radius: 8px !important;
        }

        /* Card Smoothing */
        .cart-item {
            padding: 14px !important;
            gap: 16px !important;
            border-radius: 14px !important;
            border-width: 1.5px !important;
        }

        .cart-item-image-wrapper {
            width: 85px !important;
            height: 85px !important;
            min-width: 85px !important;
            border-radius: 10px !important;
        }

        /* Mobile Specific Refinements */
        @media (max-width: 768px) {
            .cart-title {
                font-size: 22px !important;
            }

            .back-to-menu-btn {
                padding: 8px 16px !important;
                font-size: 12.5px !important;
            }

            .cart-item-name {
                font-size: 14.5px !important;
            }

            .cart-item-total-price {
                font-size: 17px !important;
            }

            .summary-row.total-row .price {
                font-size: 22px !important;
            }

            .cart-item-image-wrapper {
                width: 75px !important;
                height: 75px !important;
                min-width: 75px !important;
            }
        }
    </style>
    <?php require_once __DIR__ . '/includes/meta_pixel.php'; ?>
</head>

<body class="cart-page">
    <!-- Navigation Bar -->
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>


    <!-- Cart Container -->
    <div class="cart-container">
        <div class="cart-wrapper">
            <div class="cart-header">
                <h1 class="cart-title">Your Cart</h1>
                <a href="<?php echo $basePath; ?>/menu" class="back-to-menu-btn" aria-label="Back to Menu">
                    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M11.25 13.5L6.75 9L11.25 4.5" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span>Back to Menu</span>
                </a>
            </div>

            <div class="cart-content">
                <!-- Cart Items Section -->
                <div class="cart-items-section">
                    <h2 class="section-title">Cart Items</h2>
                    <div class="cart-items-wrapper">
                        <div id="cartItemsList" class="cart-items-list">
                            <!-- Items will be populated by JavaScript -->
                        </div>
                        <div id="scrollIndicator" class="scroll-indicator" style="display: none;">
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
                            <svg width="120" height="120" viewBox="0 0 120 120" fill="none"
                                xmlns="http://www.w3.org/2000/svg">
                                <circle cx="60" cy="60" r="50" stroke="#d0d0d0" stroke-width="2" stroke-dasharray="5 5"
                                    opacity="0.3" />
                                <path d="M40 40L60 60L80 40" stroke="#d0d0d0" stroke-width="3" stroke-linecap="round"
                                    stroke-linejoin="round" />
                                <path d="M40 80L60 60L80 80" stroke="#d0d0d0" stroke-width="3" stroke-linecap="round"
                                    stroke-linejoin="round" />
                            </svg>
                        </div>
                        <h3 class="empty-cart-title">Your cart is empty</h3>
                        <p class="empty-cart-text">Start adding delicious items from our menu</p>
                        <a href="<?php echo $basePath; ?>/menu" class="empty-cart-button">Browse Menu</a>
                    </div>
                </div>

                <!-- Cart Summary Section -->
                <div class="cart-summary-section">
                    <h2 class="section-title">Order Summary</h2>
                    <div class="summary-details">
                        <div class="summary-row">
                            <span>Subtotal:</span>
                            <span class="price" id="cartSubtotal">Rs. 0.00</span>
                        </div>
                        <div class="summary-row total-row">
                            <span>Total:</span>
                            <span class="price" id="cartTotal">Rs. 0.00</span>
                        </div>
                    </div>
                    <a href="<?php echo $basePath; ?>/basket" class="proceed-to-checkout-btn">
                        <span>Proceed to Checkout</span>
                        <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M6.75 13.5L11.25 9L6.75 4.5" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Remove Item Confirmation Modal -->
    <div class="remove-confirm-modal" id="removeConfirmModal">
        <div class="remove-confirm-backdrop"></div>
        <div class="remove-confirm-content">
            <div class="remove-confirm-icon">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M3 6H5H21" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                        stroke-linejoin="round" />
                    <path
                        d="M8 6V4C8 3.46957 8.21071 2.96086 8.58579 2.58579C8.96086 2.21071 9.46957 2 10 2H14C14.5304 2 15.0391 2.21071 15.4142 2.58579C15.7893 2.96086 16 3.46957 16 4V6M19 6V20C19 20.5304 18.7893 21.0391 18.4142 21.4142C18.0391 21.7893 17.5304 22 17 22H7C6.46957 22 5.96086 21.7893 5.58579 21.4142C5.21071 21.0391 5 20.5304 5 20V6H19Z"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                    <path d="M10 11V17" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                        stroke-linejoin="round" />
                    <path d="M14 11V17" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"
                        stroke-linejoin="round" />
                </svg>
            </div>
            <h3 class="remove-confirm-title">Remove Item</h3>
            <p class="remove-confirm-message">Are you sure you want to remove this item from your cart?</p>
            <div class="remove-confirm-buttons">
                <button class="remove-confirm-btn remove-confirm-cancel" id="removeConfirmCancel">Cancel</button>
                <button class="remove-confirm-btn remove-confirm-ok" id="removeConfirmOk">Remove</button>
            </div>
        </div>
    </div>

    <script>
        // User verification status
        const isLoggedIn = true; // User is logged in (requiredUserLogin ensures this)
        const isVerified = <?php echo json_encode($isVerified); ?>;

        // Check verification on page load
        if (isLoggedIn && !isVerified) {
            // Show verification modal after page loads
            document.addEventListener('DOMContentLoaded', function () {
                if (typeof window.showVerificationModal === 'function') {
                    window.showVerificationModal();
                }
            });
        }

        // Cart data from PHP session
        const cart = <?php echo json_encode($_SESSION['cart'] ?? []); ?>;

        // Render cart items
        function renderCart() {
            const cartItemsList = document.getElementById('cartItemsList');
            const emptyCartMessage = document.getElementById('emptyCartMessage');

            if (cart.length === 0) {
                cartItemsList.innerHTML = '';
                emptyCartMessage.style.display = 'flex';
                updateCartSummary(0);
                return;
            }

            emptyCartMessage.style.display = 'none';
            cartItemsList.innerHTML = cart.map((item, index) => {
                // Helper to parse price if it comes as string with Rs
                const parsePrice = (price) => {
                    if (typeof price === 'string') {
                        return parseFloat(price.replace(/Rs\./g, '').replace(/[^\d.]/g, '')) || 0;
                    }
                    return parseFloat(price) || 0;
                };

                const itemTotal = (parsePrice(item.price) * item.quantity).toFixed(2);
                const itemImage = item.image || 'assets/plate.png';
                return `
                    <div class="cart-item" data-index="${index}" style="animation-delay: ${index * 0.1}s;">
                        <div class="cart-item-image-wrapper">
                            <img src="${itemImage}" alt="${item.name}" class="cart-item-image" loading="lazy">
                        </div>
                        <div class="cart-item-info">
                            <span class="cart-item-name">${item.name}</span>
                            <span class="cart-item-total-price">Rs. ${itemTotal}</span>
                        </div>
                        <div class="cart-item-controls">
                            <div class="cart-qty-controls">
                                <button class="cart-qty-btn minus" data-index="${index}" data-action="decrease" aria-label="Decrease quantity">-</button>
                                <span class="cart-qty-value">${item.quantity}</span>
                                <button class="cart-qty-btn plus" data-index="${index}" data-action="increase" aria-label="Increase quantity">+</button>
                            </div>
                            <button class="cart-remove-btn" onclick="removeCartItem(${index})" aria-label="Remove item">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M3 6H5H21" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M8 6V4C8 3.46957 8.21071 2.96086 8.58579 2.58579C8.96086 2.21071 9.46957 2 10 2H14C14.5304 2 15.0391 2.21071 15.4142 2.58579C15.7893 2.96086 16 3.46957 16 4V6M19 6V20C19 20.5304 18.7893 21.0391 18.4142 21.4142C18.0391 21.7893 17.5304 22 17 22H7C6.46957 22 5.96086 21.7893 5.58579 21.4142C5.21071 21.0391 5 20.5304 5 20V6H19Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M10 11V17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                    <path d="M14 11V17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                `;
            }).join('');

            // Trigger reflow for animations
            cartItemsList.offsetHeight;

            updateCartSummary();

            // Show/hide scroll indicator after rendering with proper delays
            setTimeout(() => {
                checkScrollIndicator();
            }, 300);

            // Also check after images are loaded
            setTimeout(() => {
                checkScrollIndicator();
            }, 800);

            // Final check after everything settles
            setTimeout(() => {
                checkScrollIndicator();
            }, 1200);
        }

        // Check if scroll indicator should be shown
        let scrollCheckTimeout;
        function checkScrollIndicator() {
            const cartItemsList = document.getElementById('cartItemsList');
            const scrollIndicator = document.getElementById('scrollIndicator');

            if (!cartItemsList || !scrollIndicator) return;

            // Clear any pending timeout
            clearTimeout(scrollCheckTimeout);

            // Wait for layout to settle
            scrollCheckTimeout = setTimeout(() => {
                requestAnimationFrame(() => {
                    // Check if content is scrollable
                    const scrollHeight = cartItemsList.scrollHeight;
                    const clientHeight = cartItemsList.clientHeight;
                    const scrollTop = cartItemsList.scrollTop;

                    const isScrollable = scrollHeight > clientHeight + 10; // Add 10px threshold
                    const isAtBottom = scrollHeight - scrollTop <= clientHeight + 20; // 20px threshold for bottom detection

                    if (isScrollable && !isAtBottom && scrollTop < scrollHeight - clientHeight - 10) {
                        scrollIndicator.classList.add('show');
                    } else {
                        scrollIndicator.classList.remove('show');
                    }
                });
            }, 100); // Debounce check
        }

        // Update scroll indicator on scroll with throttling
        const cartItemsList = document.getElementById('cartItemsList');
        if (cartItemsList) {
            let scrollTimeout;
            cartItemsList.addEventListener('scroll', function () {
                clearTimeout(scrollTimeout);
                scrollTimeout = setTimeout(checkScrollIndicator, 150); // Throttle scroll events
            }, { passive: true });
        }

        // Update quantity - prevent zoom on mobile
        function updateCartQuantity(index, newQuantity, event) {
            // Prevent zoom on mobile
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }

            if (newQuantity < 1) {
                removeCartItem(index);
                return;
            }

            fetch('<?php echo $basePath; ?>/cart', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=update&index=${index}&quantity=${newQuantity}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        cart[index].quantity = newQuantity;
                        renderCart();
                    }
                })
                .catch(error => {
                    console.error('Error updating quantity:', error);
                });
        }

        // Add touch event handlers - mobile-friendly
        document.addEventListener('DOMContentLoaded', function () {
            // Store touch data for each button
            const touchData = new Map();

            // Helper function to find the cart-qty-btn element (handles clicks on child elements)
            function findCartQtyButton(element) {
                if (!element) return null;
                if (element.classList && element.classList.contains('cart-qty-btn')) {
                    return element;
                }
                return element.closest('.cart-qty-btn');
            }

            // Handle touch events for mobile (prevent zoom, handle taps)
            document.addEventListener('touchstart', function (e) {
                const button = findCartQtyButton(e.target);
                if (button) {
                    const touch = e.touches[0];
                    touchData.set(button, {
                        startTime: Date.now(),
                        startY: touch.clientY,
                        startX: touch.clientX
                    });
                }
            }, { passive: true });

            // Handle touchend for mobile taps
            document.addEventListener('touchend', function (e) {
                const button = findCartQtyButton(e.target);
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

                        const cartItem = button.closest('.cart-item');
                        if (cartItem) {
                            const index = parseInt(cartItem.dataset.index);
                            if (!isNaN(index)) {
                                const currentQty = parseInt(cart[index].quantity);

                                if (button.classList.contains('plus')) {
                                    updateCartQuantity(index, currentQty + 1, e);
                                } else if (button.classList.contains('minus')) {
                                    updateCartQuantity(index, currentQty - 1, e);
                                }
                            }
                        }
                    }

                    touchData.delete(button);
                }
            }, { passive: false });

            // Handle click events for desktop
            document.addEventListener('click', function (e) {
                const button = findCartQtyButton(e.target);
                if (button) {
                    e.preventDefault();
                    e.stopPropagation();

                    const cartItem = button.closest('.cart-item');
                    if (cartItem) {
                        const index = parseInt(cartItem.dataset.index);
                        if (!isNaN(index)) {
                            const currentQty = parseInt(cart[index].quantity);

                            if (button.classList.contains('plus')) {
                                updateCartQuantity(index, currentQty + 1, e);
                            } else if (button.classList.contains('minus')) {
                                updateCartQuantity(index, currentQty - 1, e);
                            }
                        }
                    }
                }
            }, true);
        });

        // Global Scroll Locking Mechanism
        function preventDefault(e) {
            e.preventDefault();
        }

        const scrollKeys = { 32: 1, 33: 1, 34: 1, 35: 1, 36: 1, 37: 1, 38: 1, 39: 1, 40: 1 };
        function preventDefaultForScrollKeys(e) {
            if (scrollKeys[e.keyCode]) {
                e.preventDefault();
                return false;
            }
        }

        function disableScroll() {
            document.documentElement.classList.add('modal-open');
            document.body.classList.add('modal-open');
            window.addEventListener('DOMMouseScroll', preventDefault, false);
            window.addEventListener('wheel', preventDefault, { passive: false });
            window.addEventListener('touchmove', preventDefault, { passive: false });
            window.addEventListener('keydown', preventDefaultForScrollKeys, false);
        }

        function enableScroll() {
            document.documentElement.classList.remove('modal-open');
            document.body.classList.remove('modal-open');
            window.removeEventListener('DOMMouseScroll', preventDefault, false);
            window.removeEventListener('wheel', preventDefault, { passive: false });
            window.removeEventListener('touchmove', preventDefault, { passive: false });
            window.removeEventListener('keydown', preventDefaultForScrollKeys, false);
        }

        // Remove item with modern confirmation modal
        let pendingRemoveIndex = null;
        const removeConfirmModal = document.getElementById('removeConfirmModal');
        const removeConfirmOk = document.getElementById('removeConfirmOk');
        const removeConfirmCancel = document.getElementById('removeConfirmCancel');
        const removeConfirmBackdrop = removeConfirmModal ? removeConfirmModal.querySelector('.remove-confirm-backdrop') : null;

        function showRemoveConfirm(index) {
            pendingRemoveIndex = index;
            if (removeConfirmModal) {
                disableScroll();
                removeConfirmModal.classList.add('active');
            }
        }

        function hideRemoveConfirm() {
            pendingRemoveIndex = null;
            if (removeConfirmModal) {
                enableScroll();
                removeConfirmModal.classList.remove('active');
            }
        }

        function removeCartItem(index) {
            showRemoveConfirm(index);
        }

        // Handle confirmation
        if (removeConfirmOk) {
            removeConfirmOk.addEventListener('click', function () {
                if (pendingRemoveIndex !== null) {
                    const indexToRemove = pendingRemoveIndex;
                    hideRemoveConfirm();

                    fetch('<?php echo $basePath; ?>/cart', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: `action=remove&index=${indexToRemove}`
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                cart.splice(indexToRemove, 1);
                                renderCart();
                            }
                        })
                        .catch(error => {
                            console.error('Error removing item:', error);
                        });
                }
            });
        }

        // Handle cancel
        if (removeConfirmCancel) {
            removeConfirmCancel.addEventListener('click', hideRemoveConfirm);
        }

        if (removeConfirmBackdrop) {
            removeConfirmBackdrop.addEventListener('click', hideRemoveConfirm);
        }

        // Close on Escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && removeConfirmModal && removeConfirmModal.classList.contains('active')) {
                hideRemoveConfirm();
            }
        });

        // Update cart summary
        function updateCartSummary() {
            // Helper to parse price
            const parsePrice = (price) => {
                if (typeof price === 'string') {
                    return parseFloat(price.replace(/Rs\./g, '').replace(/[^\d.]/g, '')) || 0;
                }
                return parseFloat(price) || 0;
            };

            const subtotal = cart.reduce((sum, item) => sum + (parsePrice(item.price) * item.quantity), 0);

            const cartSubtotal = document.getElementById('cartSubtotal');
            const cartTotal = document.getElementById('cartTotal');

            if (cartSubtotal) cartSubtotal.textContent = `Rs. ${subtotal.toFixed(2)}`;
            if (cartTotal) cartTotal.textContent = `Rs. ${subtotal.toFixed(2)}`;
        }



        // Chroma key solution for removing black background (Walking Character)
        (function () {
            const video = document.getElementById('walkerVideo');
            const canvas = document.getElementById('walkerCanvas');
            if (!video || !canvas) return;

            const ctx = canvas.getContext('2d');
            let animationFrameId = null;
            let isProcessing = false;
            let displayWidth = 120;
            let displayHeight = 170;

            function setCanvasSize() {
                if (video.videoWidth && video.videoHeight) {
                    const aspectRatio = video.videoHeight / video.videoWidth;
                    const isMobile = window.innerWidth <= 768;
                    const isSmallMobile = window.innerWidth <= 480;

                    if (isSmallMobile) {
                        displayWidth = 90;
                        displayHeight = Math.max(Math.round(displayWidth * aspectRatio), 110);
                    } else if (isMobile) {
                        displayWidth = 100;
                        displayHeight = Math.max(Math.round(displayWidth * aspectRatio), 120);
                    } else {
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
                    } else if ((g > 70) && (g > r + 15) && (g > b + 15)) {
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
                }, 250);
            });

            if (video.readyState >= video.HAVE_METADATA) {
                setCanvasSize();
                video.play().catch(e => console.log('Video play error:', e));
                setTimeout(startProcessing, 100);
            } else {
                setTimeout(reloadVideo, 100);
            }

            window.addEventListener('beforeunload', function () {
                if (animationFrameId) {
                    cancelAnimationFrame(animationFrameId);
                }
            });
        })();

        // Language Switcher Functionality
        (function () {
            const langButtons = document.querySelectorAll('.lang-btn');
            let currentLang = localStorage.getItem('selectedLanguage') || 'en';

            function initLanguage() {
                langButtons.forEach(btn => {
                    const lang = btn.getAttribute('data-lang');
                    if (lang === currentLang) {
                        btn.classList.add('active');
                    } else {
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
                    } else {
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

        // Initialize
        renderCart();
    </script>

    <!-- Footer Section -->
    <?php require_once __DIR__ . '/includes/footer.php'; ?>

    <!-- Email Verification Required Modal -->
    <?php if (!$isVerified): ?>
        <?php require_once __DIR__ . '/includes/verification_modal.php'; ?>
    <?php endif; ?>

    <!-- Modal Auto-Show Logic -->
    <?php if ($isLoggedIn && !$isVerified): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                if (typeof showVerificationModal === 'function') {
                    showVerificationModal();
                }
            });
        </script>
    <?php endif; ?>

    <script src="<?php echo $basePath; ?>/assets/js/dv_mobile_nav.js" defer></script>
</body>

</html>