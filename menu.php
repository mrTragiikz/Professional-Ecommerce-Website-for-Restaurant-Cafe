<?php
// Security initialization (must be first)
require_once __DIR__ . '/app/functions/security_init.php';

// Database connection
require_once __DIR__ . '/config/db.php';

require_once __DIR__ . '/app/functions/auth.php';
$isLoggedIn = isUserLoggedIn();
$user = $isLoggedIn ? getCurrentUser() : null;
// If getCurrentUser returns false (user not found in DB), treat as not logged in
if ($user === false) {
    $user = null;
    $isLoggedIn = false;
    // Clear invalid session
    if (isset($_SESSION['user_id'])) {
        unset($_SESSION['user_id']);
        unset($_SESSION['user_email']);
    }
}
$isVerified = $isLoggedIn && $user && ($user['is_verified'] ?? 0);
$basePath = getBasePath();

/**
 * Get dish image path
 * 
 * @param array $item The item array from DB (containing image_path)
 * @param string $category The category key
 * @param string $basePath The base path for URLs
 * @return string Image path
 */
function getDishImagePath($item, $category, $basePath)
{
    // 1. Check if explicit image path exists in DB
    if (!empty($item['image_path']) && file_exists(__DIR__ . '/' . $item['image_path'])) {
        return $basePath . '/' . $item['image_path'];
    }

    $dishName = $item['name'];

    // List of Mo:Mo dishes to always fetch from 'momo' folder
    if (strpos(strtolower($dishName), 'mo:mo') !== false || strpos(strtolower($dishName), 'momo') !== false) {
        $category = 'momo';
    }

    // List of Biryani dishes to always fetch from 'biryani' folder
    if (strpos(strtolower($dishName), 'biryani') !== false) {
        $category = 'biryani';
    }

    // Default: Convert dish name to filename: remove spaces, slashes, and special chars, lowercase, add .jpg
    $filename = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $dishName)) . '.jpg';

    // Map category to folder name (if needed)
    $folderName = strtolower(str_replace([' ', '&', '/'], '-', $category));

    // Special cases for folder naming
    $categoryFolders = [
        'chowmein-thukpa' => 'chow-mein',
        'non-veg-snacks' => 'non-veg-snacks',
        'veg-snacks' => 'veg-snacks',
        'non-veg-curry' => 'main-course',
        'veg-curry' => 'vegetarian-curry',
        'taas-sekuwa' => 'vatti-special',
        'choila-sadheko' => 'khaja-set',
        'kathi-shawarma-rolls' => 'starters',
        'tandoori-kebab' => 'entree-takeaway',
        'soft-drinks' => 'drinks',
        'beer-selection' => 'drinks',
        'nandoz-pizza' => 'nandoz-pizza',
        'burgers-sandwich' => 'kids-meal-takeaway'
    ];

    $folder = $categoryFolders[$category] ?? $folderName;

    // Check if file exists
    $relativePath = '/assets/' . $folder . '/' . $filename;
    if (file_exists(__DIR__ . $relativePath)) {
        return $basePath . $relativePath;
    }

    // Fallback to default plate image
    return $basePath . '/assets/plate.png';
}

// Fetch menu from database using a single optimized query
$menu_categories = [];
$branchId = $_SESSION['customer_branch_id'] ?? 1; // Default to main branch
try {
    if (isset($pdo) && $pdo !== null) {
        // Single query to fetch all active items for the selected branch
        $stmt = $pdo->prepare("
            SELECT 
                m.id,
                c.id as cat_id, 
                c.category_key, 
                c.category_name, 
                c.display_order as cat_order,
                m.item_name, 
                m.item_description, 
                m.price, 
                m.old_price,
                m.offer_end_time,
                m.image_path, 
                m.display_order as item_order,
                m.stock_count,
                m.track_stock,
                m.is_active
            FROM menu_categories c
            INNER JOIN menu_items m ON c.id = m.category_id
            WHERE c.is_active = 1 
            AND c.category_name != 'Combo Offers' 
            AND m.restaurant_id = ?
            ORDER BY CASE WHEN c.category_key LIKE '%grocery%' OR c.category_name LIKE '%Grocery%' OR c.category_name LIKE '%Groceries%' THEN 1 ELSE 0 END ASC, c.display_order ASC, m.display_order ASC
        ");
        $stmt->execute([$branchId]);

        $all_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group items by category in memory
        foreach ($all_data as $row) {
            $key = $row['category_key'];
            if (!isset($menu_categories[$key])) {
                $menu_categories[$key] = [
                    'title' => $row['category_name'],
                    'items' => []
                ];
            }

            $menu_categories[$key]['items'][] = [
                'id' => $row['id'],
                'name' => $row['item_name'],
                'description' => $row['item_description'] ?? '',
                'price' => 'Rs. ' . number_format($row['price'], 0),
                'raw_price' => $row['price'],
                'raw_old_price' => $row['old_price'] ?? 0,
                'offer_end_time' => $row['offer_end_time'] ?? null,
                'stock_count' => (int) ($row['stock_count'] ?? 0),
                'track_stock' => (int) ($row['track_stock'] ?? 0),
                'is_active' => (int) ($row['is_active'] ?? 0),
                'image_path' => $row['image_path'] ?? ''
            ];
        }
    }
} catch (Throwable $e) {
    error_log("Menu database error: " . $e->getMessage());
}
?><!DOCTYPE html>
<html lang="en">

<title>Menu | JustKleek</title>
<?php include __DIR__ . '/includes/head_standard.php'; ?>
<link rel="stylesheet" href="<?php echo $basePath; ?>/animations.css?v=<?php echo time(); ?>">
<link rel="prefetch" href="<?php echo $basePath; ?>/basket">
<script src="<?php echo $basePath; ?>/js/click-sound.js?v=1.0.1"></script>
<script>
    // Global auth state for JS files
    window.userLoggedIn = <?php echo json_encode($isLoggedIn); ?>;
    window.userVerified = <?php echo json_encode($isVerified); ?>;
    window.basePath = '<?php echo $basePath; ?>';
</script>
<?php require_once __DIR__ . '/includes/meta_pixel.php'; ?>
    <style>
        /* --- BRANCH SELECTION BAR STYLES (LOAD FIRST TO PREVENT FLASH) --- */
        .branch-selection-bar {
            background: #ffffff;
            border-radius: 22px;
            padding: 18px 25px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border: 1.5px solid #f1f5f9;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            position: relative;
            z-index: 10;
        }

        .branch-info-display {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .branch-icon-wrapper {
            width: 48px;
            height: 48px;
            background: #fff7ed;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #f97316;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(249, 115, 22, 0.1);
        }

        .branch-text-wrapper {
            display: flex;
            flex-direction: column;
        }

        .branch-label {
            display: block;
            font-size: 10px;
            font-weight: 800;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 2px;
        }

        .branch-current-name {
            display: block;
            font-size: 18px;
            font-weight: 850;
            color: #0f172a;
            letter-spacing: -0.5px;
        }

        .branch-change-btn {
            background: linear-gradient(135deg, #f97316 0%, #ea580c 100%);
            color: #ffffff;
            border: none;
            padding: 12px 24px;
            border-radius: 14px;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 4px 12px rgba(249, 115, 22, 0.2);
            white-space: nowrap;
        }

        .branch-change-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(249, 115, 22, 0.3);
            filter: brightness(1.1);
        }

        .branch-change-btn:active {
            transform: translateY(0);
        }

        /* Responsive adjustments for mobile/tablet */
        @media (max-width: 580px) {
            .branch-selection-bar {
                flex-direction: column;
                gap: 20px;
                text-align: center;
                padding: 20px;
            }
            .branch-info-display {
                flex-direction: column;
                gap: 12px;
            }
            .branch-text-wrapper {
                align-items: center;
            }
            .branch-change-btn {
                width: 100%;
            }
        }

        @media (max-width: 768px) {
            body.menu-page { overflow-x: hidden !important; }
            .navbar {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                width: 100% !important;
                z-index: 1000000 !important;
                background: #ffffff !important;
                padding-bottom: 5px !important;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1) !important;
                height: 85px !important;
            }
            .category-tabs-container {
                display: block !important;
                position: fixed !important;
                top: 85px !important;
                left: 0 !important;
                width: 100% !important;
                z-index: 999999 !important;
                background: #ffffff !important;
                margin-top: 0 !important;
                padding: 12px 0 15px 0 !important;
                border-bottom: 1px solid rgba(0, 0, 0, 0.08) !important;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05) !important;
            }
            body.menu-page .menu-wrapper { 
                padding-top: 280px !important; 
                margin-top: 0 !important; 
            }
            body.menu-page .menu-category-section { scroll-margin-top: 280px !important; }
            .menu-search-wrapper { margin: 0 16px 10px 16px !important; width: calc(100% - 32px) !important; }
            .mobile-category-selector { margin: 0 16px !important; width: calc(100% - 32px) !important; }
        }

        @media (min-width: 769px) {
            .menu-container { padding-top: 130px; position: relative; }
            .branch-selection-bar { margin-top: 0; margin-bottom: 35px; max-width: 100%; }
        }

        /* Generic Out of Stock */
        .menu-card.out-of-stock { opacity: 0.8; filter: grayscale(0.3); }
        .menu-card.out-of-stock .menu-card-image::after {
            content: 'OUT OF STOCK';
            position: absolute; top: 50%; left: 50%;
            transform: translate(-50%, -50%) rotate(-15deg);
            background: rgba(227, 10, 10, 0.85); color: white;
            padding: 4px 12px; font-weight: 700; font-size: 13px; border-radius: 4px; z-index: 5;
        }
    </style>
</head>

<body class="menu-page page-menu">
    <!-- Navigation Bar -->
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>


    <!-- Sticky Category Tabs with Search -->
    <div class="category-tabs-container" id="categoryTabs">
        <div class="menu-header-controls">
            <!-- Search Bar - Top -->
            <div class="menu-search-wrapper">
                <svg class="search-icon" width="20" height="20" viewBox="0 0 20 20" fill="none"
                    xmlns="http://www.w3.org/2000/svg">
                    <path
                        d="M9 17C13.4183 17 17 13.4183 17 9C17 4.58172 13.4183 1 9 1C4.58172 1 1 4.58172 1 9C1 13.4183 4.58172 17 9 17Z"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                    <path d="M19 19L14.65 14.65" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round" />
                </svg>
                <input type="text" id="menuSearch" class="menu-search-input" placeholder="Search dishes...">
            </div>
            <!-- Mobile Category Selector -->
            <div class="mobile-category-selector">
                <button class="mobile-category-btn" id="mobileCategoryBtn">
                    <span id="mobileCategoryText">All Categories</span>
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M4 6L8 10L12 6" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" />
                    </svg>
                </button>
                <div class="mobile-category-dropdown" id="mobileCategoryDropdown">
                    <button class="mobile-category-option active" data-category="all">
                        <span>All</span>
                    </button>
                    <?php foreach ($menu_categories as $key => $category): ?>
                        <button class="mobile-category-option" data-category="<?php echo $key; ?>">
                            <span><?php echo $category['title']; ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- Category Tabs - Desktop Only -->
            <div class="category-tabs-wrapper">
                <div class="category-tabs">
                    <button class="category-tab active" data-category="all">
                        <span class="category-text">All</span>
                    </button>
                    <?php foreach ($menu_categories as $key => $category): ?>
                        <button class="category-tab" data-category="<?php echo $key; ?>">
                            <span class="category-text"><?php echo $category['title']; ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Menu Content -->
    <div class="menu-wrapper">
        <!-- Sidebar Categories - Desktop Only -->
        <aside class="menu-sidebar" id="menuSidebar">
            <div class="sidebar-header">
                <h3 class="sidebar-title">Filter By</h3>
            </div>
            <div class="sidebar-categories">
                <h4 class="sidebar-categories-title">Categories</h4>
                <div class="sidebar-category-list">
                    <button class="sidebar-category-item active" data-category="all">
                        <span>All</span>
                    </button>
                    <?php foreach ($menu_categories as $key => $category): ?>
                        <button class="sidebar-category-item" data-category="<?php echo $key; ?>">
                            <span><?php echo $category['title']; ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </aside>


        <!-- Main Menu Container -->
        <div class="menu-container">
            <?php if (!$isLoggedIn): ?>
                <!-- Branch Selection Bar (Guest Only) -->
                <div class="branch-selection-bar">
                    <div class="branch-info-display">
                        <div class="branch-icon-wrapper">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                <circle cx="12" cy="10" r="3"></circle>
                            </svg>
                        </div>
                        <div class="branch-text-wrapper">
                            <span class="branch-label">Your Nearest Branch</span>
                            <span class="branch-current-name"><?php echo htmlspecialchars($branchName ?: 'Select Branch'); ?></span>
                        </div>
                    </div>
                    <button class="branch-change-btn" onclick="openBranchModal()">
                        <?php echo $branchName ? 'Change Branch' : 'Select Branch'; ?>
                    </button>
                </div>
            <?php endif; ?>
            <div class="menu-empty-state" id="menuEmptyState" style="display: none;">
                <svg width="120" height="120" viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="60" cy="60" r="50" stroke="currentColor" stroke-width="2" stroke-dasharray="5 5"
                        opacity="0.3" />
                    <path d="M40 60L55 75L80 50" stroke="currentColor" stroke-width="3" stroke-linecap="round"
                        stroke-linejoin="round" />
                </svg>
                <h2>Select a Category</h2>
                <p>Choose a category above to view our delicious menu items</p>
            </div>
            <?php foreach ($menu_categories as $key => $category): ?>
                <section class="menu-category-section" id="<?php echo $key; ?>">
                    <h2 class="category-title">
                        <span class="category-title-text"><?php echo $category['title']; ?></span>
                    </h2>
                    <div class="menu-grid">
                        <?php foreach ($category['items'] as $item):
                            // Item is out of stock ONLY if stock tracking is enabled for this item AND quantity is 0.
                            // Use item.track_stock (DB flag) instead of fragile category-title matching.
                            $isOutOfStock = ((int)($item['track_stock'] ?? 0) === 1) && ((int)($item['stock_count'] ?? 0) <= 0);
                            ?>
                            <div class="menu-card <?php echo $isOutOfStock ? 'out-of-stock' : ''; ?>"
                                data-item-id="<?php echo $item['id']; ?>"
                                data-category="<?php echo $key; ?>"
                                data-category-title="<?php echo strtolower(htmlspecialchars($category['title'])); ?>"
                                data-item-name="<?php echo strtolower(htmlspecialchars($item['name'])); ?>"
                                data-item-description="<?php echo strtolower(htmlspecialchars($item['description'])); ?>"
                                data-display-name="<?php echo htmlspecialchars($item['name']); ?>"
                                data-display-description="<?php echo htmlspecialchars($item['description']); ?>">
                                <div class="menu-card-image" style="position: relative;">
                                    <?php if (!empty($item['raw_old_price']) && $item['raw_old_price'] != $item['raw_price']):
                                        $maxPrice = max($item['raw_old_price'], $item['raw_price']);
                                        $minPrice = min($item['raw_old_price'], $item['raw_price']);
                                        $discountPercent = round((($maxPrice - $minPrice) / $maxPrice) * 100);
                                        ?>
                                        <div class="discount-badge"
                                            style="position: absolute; top: 10px; left: 10px; background: #E31837; color: white; padding: 4px 8px; border-radius: 4px; font-weight: 800; font-size: 0.85rem; z-index: 10;">
                                            <?php echo $discountPercent; ?>%<small
                                                style="font-size: 10px; margin-left: 2px;">OFF</small>
                                        </div>
                                    <?php endif; ?>
                                    <img src="<?php echo getDishImagePath($item, $key, $basePath); ?>"
                                        alt="<?php echo htmlspecialchars($item['name']); ?>" loading="lazy">
                                </div>
                                <div class="menu-card-content">
                                    <h3 class="menu-item-name"><?php echo htmlspecialchars($item['name']); ?></h3>
                                    <p class="menu-item-description"><?php echo htmlspecialchars($item['description']); ?></p>
                                    <?php if (isset($item['price'])): ?>
                                        <div class="menu-item-price">
                                            <?php if (!empty($item['raw_old_price']) && $item['raw_old_price'] != $item['raw_price']): ?>
                                                <span
                                                    style="color: #E31837; font-weight:800; margin-right:8px;"><?php echo $item['price']; ?></span>
                                                <span style="text-decoration: line-through; color: #000000; font-size:0.9em;">Rs.
                                                    <?php echo number_format($item['raw_old_price'], 0); ?></span>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($item['price']); ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php
                                    // Offer Timer Calculation
                                    $offerEndTime = $item['offer_end_time'] ?? null;
                                    $remainingSeconds = 0;
                                    if ($offerEndTime && strtotime($offerEndTime) > time()) {
                                        $remainingSeconds = strtotime($offerEndTime) - time();
                                    }
                                    ?>
                                    <?php if ($remainingSeconds > 0): ?>
                                        <div class="offer-timer" data-seconds="<?php echo $remainingSeconds; ?>"
                                            style="color: #ea580c; font-size: 0.85rem; font-weight: 600; margin-bottom: 8px; display: flex; align-items: center; justify-content: center; gap: 4px;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="12" r="10"></circle>
                                                <polyline points="12 6 12 12 16 14"></polyline>
                                            </svg>
                                            <span>Ends in: <span class="timer-display"></span></span>
                                        </div>
                                    <?php endif; ?>
                                    <div class="menu-card-footer">
                                        <button class="menu-item-btn" <?php echo $isOutOfStock ? 'disabled' : ''; ?>>
                                            <span><?php echo $isOutOfStock ? 'Out of Stock' : 'Order Now'; ?></span>
                                            <?php if (!$isOutOfStock): ?>
                                                <svg width="18" height="18" viewBox="0 0 18 18" fill="none"
                                                    xmlns="http://www.w3.org/2000/svg">
                                                    <path d="M6.75 13.5L11.25 9L6.75 4.5" stroke="currentColor" stroke-width="2"
                                                        stroke-linecap="round" stroke-linejoin="round" />
                                                </svg>
                                            <?php endif; ?>
                                        </button>
                                        <button class="menu-cart-btn" aria-label="Add to cart" <?php echo $isOutOfStock ? 'disabled style="display:none;"' : ''; ?>>
                                            <svg width="18" height="18" viewBox="0 0 20 20" fill="none"
                                                xmlns="http://www.w3.org/2000/svg">
                                                <path d="M5 7H15L14 13H6L5 7Z" stroke="currentColor" stroke-width="1.5"
                                                    stroke-linecap="round" stroke-linejoin="round" />
                                                <path
                                                    d="M7.5 16.5C8.05228 16.5 8.5 16.0523 8.5 15.5C8.5 14.9477 8.05228 14.5 7.5 14.5C6.94772 14.5 6.5 14.9477 6.5 15.5C6.5 16.0523 6.94772 16.5 7.5 16.5Z"
                                                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                                    stroke-linejoin="round" />
                                                <path
                                                    d="M13.5 16.5C14.0523 16.5 14.5 16.0523 14.5 15.5C14.5 14.9477 14.0523 14.5 13.5 14.5C12.9477 14.5 12.5 14.9477 12.5 15.5C12.5 16.0523 12.9477 16.5 13.5 16.5Z"
                                                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                                    stroke-linejoin="round" />
                                                <path d="M5 7L4 3H2" stroke="currentColor" stroke-width="1.5"
                                                    stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                            <span>Cart</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </div> <!-- End menu-wrapper -->

    <!-- Order Popup Modal -->
    <div class="order-modal" id="orderModal">
        <div class="modal-backdrop"></div>
        <div class="modal-content">
            <button class="modal-close" id="modalClose">&times;</button>

            <!-- Dish Image -->
            <div class="modal-dish-image">
                <img id="modalDishImage" src="" alt="Dish image">
            </div>

            <!-- Dish Info -->
            <div class="modal-dish-info">
                <h2 class="modal-title" id="modalDishName"></h2>
                <p class="modal-description" id="modalDescription"></p>
            </div>

            <!-- Service Type (Hidden - Delivery Only) -->
            <input type="hidden" id="modalServiceType" value="delivery">

            <!-- Delivery Location -->
            <div class="modal-delivery-location" id="modalDeliveryLocation">
                <label for="modalDeliverySuburb" class="modal-form-label">Delivery Location*</label>
                <div class="custom-dropdown" id="deliverySuburbDropdown">
                    <div class="dropdown-input-wrapper">
                        <input type="text" id="modalDeliverySuburb" class="dropdown-input"
                            placeholder="Search or select location..." autocomplete="off" readonly>
                        <svg class="dropdown-arrow" width="14" height="9" viewBox="0 0 14 9" fill="none"
                            xmlns="http://www.w3.org/2000/svg">
                            <path d="M1 1.5L7 7.5L13 1.5" stroke="#333" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" />
                        </svg>
                    </div>
                    <div class="dropdown-list" id="deliverySuburbList">
                        <div class="dropdown-search">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none"
                                xmlns="http://www.w3.org/2000/svg">
                                <path
                                    d="M7.33333 12.6667C10.2789 12.6667 12.6667 10.2789 12.6667 7.33333C12.6667 4.38781 10.2789 2 7.33333 2C4.38781 2 2 4.38781 2 7.33333C2 10.2789 4.38781 12.6667 7.33333 12.6667Z"
                                    stroke="#999" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                <path d="M14 14L11.1 11.1" stroke="#999" stroke-width="1.5" stroke-linecap="round"
                                    stroke-linejoin="round" />
                            </svg>
                            <input type="text" id="suburbSearchInput" class="dropdown-search-input"
                                placeholder="Type to search..." autocomplete="off">
                        </div>
                        <div class="dropdown-options" id="suburbOptionsList">
                            <!-- Options will be populated by JavaScript -->
                        </div>
                    </div>
                </div>
                <input type="hidden" id="modalDeliverySuburbValue" value="">

            </div>



            <!-- Quantity Controls -->
            <div class="modal-quantity-section">
                <label class="quantity-label">Quantity</label>
                <div class="quantity-controls">
                    <button class="qty-btn decrease" id="qtyDecrease">−</button>
                    <input type="number" id="qtyInput" value="1" min="1" readonly>
                    <button class="qty-btn increase" id="qtyIncrease">+</button>
                </div>
            </div>

            <!-- Special Instructions -->
            <div class="modal-special-instructions">
                <label for="specialInstructions">Special Instructions</label>
                <textarea id="specialInstructions"
                    placeholder="Any special requests or dietary requirements..."></textarea>
            </div>

            <!-- Order Button -->
            <button class="btn-order-now" id="btnOrderNow">
                <span id="orderBtnText">Order for Delivery</span>
            </button>
        </div>
    </div>

    <!-- Login/Signup Required Modal -->
    <div class="login-required-modal" id="loginRequiredModal">
        <div class="modal-backdrop"></div>
        <div class="login-modal-content">
            <button class="login-modal-close" id="loginModalClose">&times;</button>
            <div class="login-modal-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Clock Hands (Minute & Hour) -->
                    <path d="M12 12L12 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                    <path d="M12 12L16 14" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
            </div>
            <h2 class="login-modal-title">Login Required</h2>
            <p class="login-modal-message">Please login or sign up to place an order</p>
            <div class="login-modal-actions">
                <a href="<?php echo $basePath; ?>/auth/login.php?next=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>"
                    class="login-modal-btn login-modal-btn-primary">
                    <div
                        style="width: 24px; height: 24px; border: 2px solid rgba(255,255,255,0.4); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 10px;">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M10 10C11.3807 10 12.5 8.88071 12.5 7.5C12.5 6.11929 11.3807 5 10 5C8.61929 5 7.5 6.11929 7.5 7.5C7.5 8.88071 8.61929 10 10 10Z"
                                fill="currentColor" />
                            <path
                                d="M10 11.25C7.92893 11.25 6.25 12.9289 6.25 15V16.25C6.25 16.6642 6.58579 17 7 17H13C13.4142 17 13.75 16.6642 13.75 16.25V15C13.75 12.9289 12.0711 11.25 10 11.25Z"
                                fill="currentColor" />
                        </svg>
                    </div>
                    Login
                </a>
                <a href="<?php echo $basePath; ?>/auth/signup.php?next=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>"
                    class="login-modal-btn login-modal-btn-secondary">
                    <div
                        style="width: 24px; height: 24px; border: 2px solid rgba(227, 24, 55, 0.3); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 10px;">
                        <svg width="14" height="14" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M10 10C11.3807 10 12.5 8.88071 12.5 7.5C12.5 6.11929 11.3807 5 10 5C8.61929 5 7.5 6.11929 7.5 7.5C7.5 8.88071 8.61929 10 10 10Z"
                                fill="currentColor" />
                            <path
                                d="M10 11.25C7.92893 11.25 6.25 12.9289 6.25 15V16.25C6.25 16.6642 6.58579 17 7 17H13C13.4142 17 13.75 16.6642 13.75 16.25V15C13.75 12.9289 12.0711 11.25 10 11.25Z"
                                fill="currentColor" />
                        </svg>
                    </div>
                    Sign Up
                </a>
            </div>
            <p class="login-modal-footer">New to JustKleek? Create an account to get started!</p>
        </div>
    </div>

    <!-- Verification Required Modal -->
    <div class="login-required-modal" id="verificationRequiredModal">
        <div class="modal-backdrop"></div>
        <div class="login-modal-content">
            <button class="login-modal-close" id="verificationModalClose">&times;</button>
            <div class="login-modal-icon">
                <svg width="64" height="64" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="32" cy="32" r="30" stroke="currentColor" stroke-width="2" stroke-dasharray="5 5"
                        opacity="0.3" />
                    <path d="M32 20V32L40 40" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round" />
                </svg>
            </div>
            <h2 class="login-modal-title">Verify Your Email</h2>
            <p class="login-modal-message">Please verify your email before placing orders.</p>
            <div class="login-modal-actions">
                <button class="login-modal-btn login-modal-btn-primary" id="verificationModalVerifyBtn">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M10 10C11.3807 10 12.5 8.88071 12.5 7.5C12.5 6.11929 11.3807 5 10 5C8.61929 5 7.5 6.11929 7.5 7.5C7.5 8.88071 8.61929 10 10 10Z"
                            fill="currentColor" />
                        <path
                            d="M10 11.25C7.92893 11.25 6.25 12.9289 6.25 15V16.25C6.25 16.6642 6.58579 17 7 17H13C13.4142 17 13.75 16.6642 13.75 16.25V15C13.75 12.9289 12.0711 11.25 10 11.25Z"
                            fill="currentColor" />
                        <path
                            d="M10 2C5.58172 2 2 5.58172 2 10C2 14.4183 5.58172 18 10 18C14.4183 18 18 14.4183 18 10C18 5.58172 14.4183 2 10 2ZM10 16.5C6.41015 16.5 3.5 13.5899 3.5 10C3.5 6.41015 6.41015 3.5 10 3.5C13.5899 3.5 16.5 6.41015 16.5 10C16.5 13.5899 13.5899 16.5 10 16.5Z"
                            fill="currentColor" />
                    </svg>
                    Verify Now
                </button>
                <button class="login-modal-btn login-modal-btn-secondary" id="verificationModalCancelBtn">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M10 10C11.3807 10 12.5 8.88071 12.5 7.5C12.5 6.11929 11.3807 5 10 5C8.61929 5 7.5 6.11929 7.5 7.5C7.5 8.88071 8.61929 10 10 10Z"
                            fill="currentColor" />
                        <path
                            d="M10 11.25C7.92893 11.25 6.25 12.9289 6.25 15V16.25C6.25 16.6642 6.58579 17 7 17H13C13.4142 17 13.75 16.6642 13.75 16.25V15C13.75 12.9289 12.0711 11.25 10 11.25Z"
                            fill="currentColor" />
                        <path
                            d="M10 2C5.58172 2 2 5.58172 2 10C2 14.4183 5.58172 18 10 18C14.4183 18 18 14.4183 18 10C18 5.58172 14.4183 2 10 2ZM10 16.5C6.41015 16.5 3.5 13.5899 3.5 10C3.5 6.41015 6.41015 3.5 10 3.5C13.5899 3.5 16.5 6.41015 16.5 10C16.5 13.5899 13.5899 16.5 10 16.5Z"
                            fill="currentColor" />
                    </svg>
                    Maybe Later
                </button>
            </div>
            <p class="login-modal-footer">You'll be redirected to verify from your profile.</p>
        </div>
    </div>

    <script src="js/menu.js?v=1.0.1"></script>
    <script>
        // Chroma key solution for removing black background - Walking Character Animation
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
            // Use high DPI rendering for better quality
            function setCanvasSize() {
                if (video.videoWidth && video.videoHeight) {
                    const aspectRatio = video.videoHeight / video.videoWidth;
                    // Adjust size based on screen width
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

                    // Get device pixel ratio for high-quality rendering
                    const dpr = window.devicePixelRatio || 2;
                    const scale = Math.min(dpr, 2.5); // Cap at 2.5x for performance

                    // Set canvas internal resolution (higher for quality)
                    canvas.width = displayWidth * scale;
                    canvas.height = displayHeight * scale;

                    // Set CSS display size
                    canvas.style.width = displayWidth + 'px';
                    canvas.style.height = displayHeight + 'px';

                    // Scale context for high DPI (after setting canvas dimensions)
                    ctx.setTransform(scale, 0, 0, scale, 0, 0);

                    // Enable high-quality image smoothing
                    ctx.imageSmoothingEnabled = true;
                    ctx.imageSmoothingQuality = 'high';
                }
            }

            // Chroma key function to remove green background (preserve character colors)
            function chromaKey() {
                // Get full image data using the physical canvas dimensions
                const width = canvas.width;
                const height = canvas.height;

                if (width === 0 || height === 0) return;

                const imageData = ctx.getImageData(0, 0, width, height);
                const data = imageData.data;

                for (let i = 0; i < data.length; i += 4) {
                    const r = data[i];
                    const g = data[i + 1];
                    const b = data[i + 2];

                    // Improved Green Screen Algorithm
                    // Condition: Green must be significantly brighter than Red and Blue
                    const isGreenDominant = (g > 90) && (g > r + 30) && (g > b + 30);

                    if (isGreenDominant) {
                        data[i + 3] = 0;
                    }
                    // Soft edge for anti-aliasing
                    else if ((g > 70) && (g > r + 15) && (g > b + 15)) {
                        data[i + 3] = Math.min(data[i + 3], 100);
                    }
                }

                ctx.putImageData(imageData, 0, 0);
            }

            // Draw video frame to canvas
            function drawFrame() {
                if (!video.paused && video.readyState >= video.HAVE_CURRENT_DATA) {
                    if (canvas.width === 0 || canvas.height === 0) {
                        setCanvasSize();
                    }

                    if (canvas.width > 0 && canvas.height > 0 && video.videoWidth > 0 && video.videoHeight > 0) {
                        const videoAspect = video.videoHeight / video.videoWidth;

                        // Clear canvas first (using display dimensions since context is scaled)
                        ctx.clearRect(0, 0, displayWidth, displayHeight);

                        // Calculate drawing dimensions for display size
                        let drawWidth = displayWidth;
                        let drawHeight = drawWidth * videoAspect;
                        let drawX = 0;
                        let drawY = (displayHeight - drawHeight) / 2;

                        // Draw full video frame at high quality (context is already scaled)
                        ctx.drawImage(video, 0, 0, video.videoWidth, video.videoHeight, drawX, drawY, drawWidth, drawHeight);

                        // Apply chroma key
                        chromaKey();
                    }
                }

                if (isProcessing) {
                    animationFrameId = requestAnimationFrame(drawFrame);
                }
            }

            // Start processing when video can play
            function startProcessing() {
                if (!isProcessing) {
                    isProcessing = true;
                    setCanvasSize();
                    drawFrame();
                }
            }

            // Ensure video plays
            video.addEventListener('loadedmetadata', function () {
                console.log('Video loaded - Dimensions:', video.videoWidth, 'x', video.videoHeight);
                setCanvasSize();
                video.play().catch(e => console.log('Video play error:', e));
            });

            video.addEventListener('canplay', function () {
                console.log('Video can play');
                startProcessing();
            });

            video.addEventListener('play', function () {
                console.log('Video playing');
                if (!isProcessing) {
                    startProcessing();
                }
            });

            video.addEventListener('loadeddata', function () {
                console.log('Video data loaded');
                setCanvasSize();
            });

            // Force reload video with cache busting
            function reloadVideo() {
                const source = document.getElementById('videoSource');
                const currentSrc = source.getAttribute('src');
                // Add timestamp to bypass cache
                const separator = currentSrc.includes('?') ? '&' : '?';
                source.setAttribute('src', currentSrc + separator + '_t=' + Date.now());
                video.load();
                console.log('Video reloaded with cache busting');
            }

            // Reload video source to ensure new file is loaded
            reloadVideo();

            // Reload on visibility change (when tab becomes active)
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) {
                    reloadVideo();
                }
            });

            // Handle window resize to adjust canvas size
            let resizeTimeout;
            window.addEventListener('resize', function () {
                clearTimeout(resizeTimeout);
                resizeTimeout = setTimeout(function () {
                    setCanvasSize();
                }, 250);
            });

            // Start immediately if video is ready
            if (video.readyState >= video.HAVE_METADATA) {
                setCanvasSize();
                video.play().catch(e => console.log('Video play error:', e));
                setTimeout(startProcessing, 100);
            } else {
                // Force load if not ready
                setTimeout(reloadVideo, 100);
            }

            // Cleanup on page unload
            window.addEventListener('beforeunload', function () {
                if (animationFrameId) {
                    cancelAnimationFrame(animationFrameId);
                }
            });
        })();

        // Navbar scroll effect
        // Navbar scroll effect
        const navbar = document.querySelector('.navbar');
        if (navbar) {
            window.addEventListener('scroll', () => {
                if (window.scrollY > 50) {
                    navbar.classList.add('scrolled');
                } else {
                    navbar.classList.remove('scrolled');
                }
            });
        }



        // ==========================================
        // ONE PAGE SCROLL & GLOBAL SEARCH LOGIC
        // ==========================================

        const categoryTabs = document.querySelectorAll('.category-tab');
        const categorySections = document.querySelectorAll('.menu-category-section');
        const menuSearch = document.getElementById('menuSearch');
        const menuEmptyState = document.getElementById('menuEmptyState');
        const allCards = document.querySelectorAll('.menu-card');

        // Track if this is the initial page load (to prevent scrolling on load)
        let isInitialLoad = true;

        // Safety check - don't return, just warn
        if (!menuSearch) {
            console.warn('Menu search element not found');
        }
        if (!menuEmptyState) {
            console.warn('Menu empty state element not found');
        }
        if (categoryTabs.length === 0) {
            console.warn('Category tabs not found');
        }
        if (categorySections.length === 0) {
            console.warn('Category sections not found');
        }

        // 1. ScrollSpy: Update active tab on scroll (only when "All" is selected)
        function updateActiveTab() {
            const activeTab = document.querySelector('.category-tab.active');
            if (!activeTab || activeTab.dataset.category !== 'all') {
                return; // Don't update if a specific category is selected
            }

            let currentSection = '';

            // Check if at the very top
            if (window.scrollY < 200) {
                currentSection = 'all';
            } else {
                // Find the section currently in view
                categorySections.forEach(section => {
                    // Skip hidden sections
                    if (section.style.display === 'none') return;

                    const sectionTop = section.offsetTop;
                    const sectionHeight = section.offsetHeight;
                    const isMobile = window.innerWidth <= 768;
                    const scrollOffset = isMobile ? 270 : 280; // Offset for fixed navbar + sticky header
                    const scrollPosition = window.scrollY + scrollOffset;

                    // Check if section is in view
                    if (scrollPosition >= sectionTop && scrollPosition < (sectionTop + sectionHeight)) {
                        currentSection = section.getAttribute('id');
                    }
                });

                // If at bottom of page, highlight last visible tab
                if ((window.innerHeight + window.scrollY) >= document.body.offsetHeight - 50) {
                    const visibleSections = Array.from(categorySections).filter(s => s.style.display !== 'none');
                    if (visibleSections.length > 0) {
                        currentSection = visibleSections[visibleSections.length - 1].getAttribute('id');
                    }
                }
            }

            // Update visible classes
            if (currentSection) {
                categoryTabs.forEach(tab => {
                    tab.classList.remove('active');
                    if (tab.dataset.category === currentSection) {
                        tab.classList.add('active');
                        // Scroll tab into view (horizontal scroll for mobile)
                        tab.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                    }
                });
            }
        }

        // 2. Click to Filter Categories
        function filterByCategory(categoryId) {
            console.log('Filtering by category:', categoryId);

            // Save selected category to localStorage
            localStorage.setItem('selectedMenuCategory', categoryId);

            // Update active tab
            categoryTabs.forEach(tab => {
                tab.classList.remove('active');
                if (tab.dataset.category === categoryId) {
                    tab.classList.add('active');
                    // Scroll tab into view (horizontal scroll for mobile) - only if not initial load
                    if (!isInitialLoad) {
                        setTimeout(() => {
                            tab.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                        }, 100);
                    }
                }
            });

            // Update mobile dropdown text
            const mobileCategoryText = document.getElementById('mobileCategoryText');
            const mobileCategoryOptions = document.querySelectorAll('.mobile-category-option');
            if (mobileCategoryText && mobileCategoryOptions) {
                mobileCategoryOptions.forEach(option => {
                    option.classList.remove('active');
                    if (option.dataset.category === categoryId) {
                        const categoryName = option.querySelector('span').textContent;
                        mobileCategoryText.textContent = categoryName;
                        option.classList.add('active');
                    }
                });
            }

            // Update sidebar
            updateSidebarActive(categoryId);

            if (categoryId === 'all') {
                // Show all sections and cards
                categorySections.forEach(section => {
                    section.style.display = 'block';
                });
                allCards.forEach(card => {
                    card.style.display = 'flex';
                });
                if (menuEmptyState) {
                    menuEmptyState.style.display = 'none';
                }
                // Scroll to top - only if not initial load
                if (!isInitialLoad) {
                    setTimeout(() => {
                        window.scrollTo({
                            top: 0,
                            behavior: "smooth"
                        });
                    }, 50);
                }
            } else {
                // Hide all sections first
                categorySections.forEach(section => {
                    section.style.display = 'none';
                });

                // Show only the selected category section
                const targetSection = document.getElementById(categoryId);
                if (targetSection) {
                    targetSection.style.display = 'block';

                    // Show all cards in this section
                    const sectionCards = targetSection.querySelectorAll('.menu-card');
                    sectionCards.forEach(card => {
                        card.style.display = 'flex';
                    });

                    // Hide empty state
                    if (menuEmptyState) {
                        menuEmptyState.style.display = 'none';
                    }

                    // Scroll to the section - only if not initial load
                    if (!isInitialLoad) {
                        setTimeout(() => {
                            // Account for fixed navbar + sticky category tabs container
                            const isMobile = window.innerWidth <= 768;
                            const headerOffset = isMobile ? 270 : 280;
                            const elementPosition = targetSection.getBoundingClientRect().top;
                            const offsetPosition = elementPosition + window.pageYOffset - headerOffset;

                            window.scrollTo({
                                top: Math.max(0, offsetPosition),
                                behavior: "smooth"
                            });
                        }, 100);
                    }
                } else {
                    console.warn('Category section not found:', categoryId);
                }
            }
        }

        // Add click handlers to category tabs (horizontal tabs)
        if (categoryTabs.length > 0) {
            categoryTabs.forEach(tab => {
                tab.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const targetId = this.dataset.category;
                    console.log('Category tab clicked:', targetId);

                    // Ensure isInitialLoad is false when user clicks
                    isInitialLoad = false;

                    // Clear search when clicking a category
                    if (menuSearch) {
                        menuSearch.value = '';
                    }

                    // Filter by category
                    filterByCategory(targetId);

                    // Update sidebar if exists
                    updateSidebarActive(targetId);
                });
            });
        }

        // Add click handlers to sidebar category items
        function attachSidebarHandlers() {
            const sidebarItems = document.querySelectorAll('.sidebar-category-item');
            console.log('Found sidebar items:', sidebarItems.length);
            if (sidebarItems.length > 0) {
                sidebarItems.forEach(item => {
                    // Check if already has listener (prevent duplicate)
                    if (item.hasAttribute('data-listener-attached')) {
                        return;
                    }
                    item.setAttribute('data-listener-attached', 'true');

                    item.addEventListener('click', function (e) {
                        e.preventDefault();
                        e.stopPropagation();
                        const targetId = this.dataset.category;
                        console.log('Sidebar category clicked:', targetId);

                        if (!targetId) {
                            console.error('No category ID found on clicked item');
                            return;
                        }

                        // Ensure isInitialLoad is false when user clicks
                        isInitialLoad = false;

                        // Clear search when clicking a category
                        if (menuSearch) {
                            menuSearch.value = '';
                        }

                        // Filter by category
                        filterByCategory(targetId);

                        // Update sidebar active state
                        updateSidebarActive(targetId);

                        // Update horizontal tabs active state
                        categoryTabs.forEach(tab => {
                            tab.classList.remove('active');
                            if (tab.dataset.category === targetId) {
                                tab.classList.add('active');
                                // Scroll tab into view
                                setTimeout(() => {
                                    tab.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                                }, 100);
                            }
                        });

                        // Update mobile dropdown
                        const mobileCategoryText = document.getElementById('mobileCategoryText');
                        const mobileCategoryOptions = document.querySelectorAll('.mobile-category-option');
                        if (mobileCategoryText && mobileCategoryOptions) {
                            mobileCategoryOptions.forEach(option => {
                                option.classList.remove('active');
                                if (option.dataset.category === targetId) {
                                    const categoryName = option.querySelector('span').textContent;
                                    mobileCategoryText.textContent = categoryName;
                                    option.classList.add('active');
                                }
                            });
                        }
                    });
                });
            } else {
                console.warn('No sidebar items found');
            }
        }

        // Attach sidebar handlers
        attachSidebarHandlers();

        // Function to update sidebar active state
        function updateSidebarActive(categoryId) {
            const sidebarItems = document.querySelectorAll('.sidebar-category-item');
            sidebarItems.forEach(item => {
                item.classList.remove('active');
                if (item.dataset.category === categoryId) {
                    item.classList.add('active');
                }
            });
        }

        // Update active tab on scroll (only when "All" is selected)
        window.addEventListener('scroll', () => {
            const activeTab = document.querySelector('.category-tab.active');
            if (activeTab && activeTab.dataset.category === 'all') {
                updateActiveTab();
            }
        });

        // 3. Global Search Functionality
        if (menuSearch) {
            menuSearch.addEventListener('input', function () {
                const searchValue = this.value.trim().toLowerCase();
                const searchKeywords = searchValue.split(/\s+/).filter(k => k.length > 0);
                let totalVisible = 0;

                if (searchValue === '') {
                    // RESET: Show based on active category filter
                    const activeTab = document.querySelector('.category-tab.active');
                    const activeCategory = activeTab ? activeTab.dataset.category : 'all';
                    filterByCategory(activeCategory);
                    return;
                }

                // Global Search: Search across all categories
                categorySections.forEach(section => {
                    const sectionCards = section.querySelectorAll('.menu-card');
                    let visibleInSection = 0;
                    const sectionId = section.getAttribute('id');

                    sectionCards.forEach(card => {
                        const name = card.dataset.itemName || '';
                        const desc = card.dataset.itemDescription || '';
                        const catTitle = card.dataset.categoryTitle || '';
                        
                        // Original searchable text
                        const originalText = `${name} ${desc} ${catTitle}`.toLowerCase();
                        // Normalized text (removes special chars like colons, dashes for flexible matching)
                        const normalizedText = originalText.replace(/[^a-z0-9\s]/g, '');

                        // Smart Match: Check if ALL keywords are found
                        // We check both the original text and a "clean" version (so momo matches mo:mo)
                        const allMatch = searchKeywords.every(keyword => {
                            const cleanKeyword = keyword.replace(/[^a-z0-9]/g, '');
                            return originalText.includes(keyword) || normalizedText.includes(cleanKeyword);
                        });

                        if (allMatch) {
                            card.style.display = 'flex';
                            visibleInSection++;
                            totalVisible++;
                        } else {
                            card.style.display = 'none';
                        }
                    });

                    // Show section if it has visible cards, hide otherwise
                    if (visibleInSection > 0) {
                        section.style.display = 'block';
                    } else {
                        section.style.display = 'none';
                    }
                });

                // Show empty state if nothing found
                if (totalVisible === 0) {
                    if (menuEmptyState) {
                        menuEmptyState.innerHTML = `
                            <svg width="120" height="120" viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="60" cy="60" r="50" stroke="currentColor" stroke-width="2" stroke-dasharray="5 5" opacity="0.3"/>
                                <path d="M45 60L55 70L75 50" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <h2>No dishes found</h2>
                            <p>We couldn't find anything matching "${this.value}"</p>
                        `;
                        menuEmptyState.style.display = 'flex';
                    }
                } else {
                    if (menuEmptyState) {
                        menuEmptyState.style.display = 'none';
                    }
                }
            });
        }

        // Initialize: Restore saved category or show all by default
        // Use DOMContentLoaded to ensure DOM is ready, or run immediately if already loaded
        function initializeCategory() {
            if (categoryTabs.length > 0 && categorySections.length > 0) {
                // Check if there's a saved category in localStorage
                const savedCategory = localStorage.getItem('selectedMenuCategory');
                const categoryToShow = savedCategory || 'all';

                // Validate that the saved category exists
                const categoryExists = categoryToShow === 'all' || document.getElementById(categoryToShow);

                if (categoryExists) {
                    // Don't scroll smoothly on initial load - just show the category
                    filterByCategoryWithoutScroll(categoryToShow);
                } else {
                    // If saved category doesn't exist, default to 'all'
                    filterByCategoryWithoutScroll('all');
                }
            }
        }

        // Filter category without smooth scrolling (for initial load)
        function filterByCategoryWithoutScroll(categoryId) {
            console.log('Filtering by category (no scroll):', categoryId);

            // Save selected category to localStorage
            localStorage.setItem('selectedMenuCategory', categoryId);

            // Update active tab
            categoryTabs.forEach(tab => {
                tab.classList.remove('active');
                if (tab.dataset.category === categoryId) {
                    tab.classList.add('active');
                }
            });

            // Update mobile dropdown text
            const mobileCategoryText = document.getElementById('mobileCategoryText');
            const mobileCategoryOptions = document.querySelectorAll('.mobile-category-option');
            if (mobileCategoryText && mobileCategoryOptions) {
                mobileCategoryOptions.forEach(option => {
                    option.classList.remove('active');
                    if (option.dataset.category === categoryId) {
                        const categoryName = option.querySelector('span').textContent;
                        mobileCategoryText.textContent = categoryName;
                        option.classList.add('active');
                    }
                });
            }

            // Update sidebar
            updateSidebarActive(categoryId);

            // Update sidebar
            updateSidebarActive(categoryId);

            if (categoryId === 'all') {
                // Show all sections and cards
                categorySections.forEach(section => {
                    section.style.display = 'block';
                });
                allCards.forEach(card => {
                    card.style.display = 'flex';
                });
                if (menuEmptyState) {
                    menuEmptyState.style.display = 'none';
                }
            } else {
                // Hide all sections first
                categorySections.forEach(section => {
                    section.style.display = 'none';
                });

                // Show only the selected category section
                const targetSection = document.getElementById(categoryId);
                if (targetSection) {
                    targetSection.style.display = 'block';

                    // Show all cards in this section
                    const sectionCards = targetSection.querySelectorAll('.menu-card');
                    sectionCards.forEach(card => {
                        card.style.display = 'flex';
                    });

                    // Hide empty state
                    if (menuEmptyState) {
                        menuEmptyState.style.display = 'none';
                    }
                } else {
                    console.warn('Category section not found:', categoryId);
                }
            }
        }

        // Initialize when DOM is ready
        function initializeAll() {
            initializeCategory();
            attachSidebarHandlers();
            // Set isInitialLoad to false after initialization completes
            setTimeout(() => {
                isInitialLoad = false;
            }, 500);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initializeAll);
        } else {
            // DOM is already ready
            initializeAll();
        }

        // Mobile Category Dropdown Functionality
        const mobileCategoryBtn = document.getElementById('mobileCategoryBtn');
        const mobileCategoryDropdown = document.getElementById('mobileCategoryDropdown');
        const mobileCategoryText = document.getElementById('mobileCategoryText');
        const mobileCategoryOptions = document.querySelectorAll('.mobile-category-option');

        if (mobileCategoryBtn && mobileCategoryDropdown) {
            // Toggle dropdown
            mobileCategoryBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                mobileCategoryBtn.classList.toggle('active');
                mobileCategoryDropdown.classList.toggle('active');
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', function (e) {
                if (!mobileCategoryBtn.contains(e.target) && !mobileCategoryDropdown.contains(e.target)) {
                    mobileCategoryBtn.classList.remove('active');
                    mobileCategoryDropdown.classList.remove('active');
                }
            });

            // Handle category selection
            mobileCategoryOptions.forEach(option => {
                option.addEventListener('click', function (e) {
                    e.stopPropagation();
                    const targetId = this.dataset.category;
                    const categoryName = this.querySelector('span').textContent;

                    // Ensure isInitialLoad is false when user clicks
                    isInitialLoad = false;

                    // Update button text
                    mobileCategoryText.textContent = categoryName;

                    // Update active state
                    mobileCategoryOptions.forEach(opt => opt.classList.remove('active'));
                    this.classList.add('active');

                    // Close dropdown
                    mobileCategoryBtn.classList.remove('active');
                    mobileCategoryDropdown.classList.remove('active');

                    // Clear search
                    if (menuSearch) {
                        menuSearch.value = '';
                    }

                    // Filter by category (this will scroll to the section)
                    filterByCategory(targetId);

                    // Update other category selectors
                    updateSidebarActive(targetId);
                    categoryTabs.forEach(tab => {
                        tab.classList.remove('active');
                        if (tab.dataset.category === targetId) {
                            tab.classList.add('active');
                        }
                    });
                });
            });
        }

        // Update mobile dropdown text when category changes
        function updateMobileCategoryText(categoryId) {
            if (mobileCategoryText && mobileCategoryOptions) {
                mobileCategoryOptions.forEach(option => {
                    if (option.dataset.category === categoryId) {
                        const categoryName = option.querySelector('span').textContent;
                        mobileCategoryText.textContent = categoryName;
                    }
                });
            }
        }

        // Update filterByCategory to also update mobile dropdown
        const originalFilterByCategory = filterByCategory;
        filterByCategory = function (categoryId) {
            originalFilterByCategory(categoryId);
            updateMobileCategoryText(categoryId);
        };

    </script>

    </div>

    <!-- Footer Section -->
    <?php require_once __DIR__ . '/includes/footer.php'; ?>

    <!-- Scroll to Top Button -->
    <button class="scroll-to-top" id="scrollToTop" aria-label="Scroll to top">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 19V5M5 12L12 5L19 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round" />
        </svg>
    </button>

    <script>
        // Scroll to Top Button Functionality
        (function () {
            const scrollToTopBtn = document.getElementById('scrollToTop');

            if (!scrollToTopBtn) return;

            // Show/hide button based on scroll position
            window.addEventListener('scroll', function () {
                if (window.pageYOffset > 300) {
                    scrollToTopBtn.classList.add('visible');
                } else {
                    scrollToTopBtn.classList.remove('visible');
                }
            });

            // Scroll to top when button is clicked
            scrollToTopBtn.addEventListener('click', function () {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
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
    </script>

    <!-- Email Verification Required Modal -->
    <?php if ($isLoggedIn && !$isVerified): ?>
        <?php require_once __DIR__ . '/includes/verification_modal.php'; ?>
    <?php endif; ?>

    <script src="<?php echo $basePath; ?>/assets/js/dv_mobile_nav.js" defer></script>
e>

    <script>
        // Live Stock Updating for Frontend
        (function () {
            // Build DOM lookup map by ID
            const cardsById = {};
            document.querySelectorAll('.menu-card[data-item-id]').forEach(card => {
                const id = card.dataset.itemId;
                if (!id) return;
                if (!cardsById[id]) cardsById[id] = [];
                cardsById[id].push(card);
            });

            function updateLiveStock() {
                // Add timestamp to query string to bypass browser cache
                const ts = Date.now();
                fetch(window.location.origin + (window.basePath || '') + '/api/get_stock.php?branch_id=<?php echo $branchId; ?>&v=' + ts)
                .then(res => res.json())
                .then(data => {
                    if (data.success && Array.isArray(data.stocks)) {
                        data.stocks.forEach(itemStatus => {
                            const id = itemStatus.id;
                            const cards = cardsById[id] || [];
                            if (cards.length === 0) return;

                            // Item is OUT only if tracking is active AND qty <= 0
                            const isTrackEnabled = parseInt(itemStatus.track_stock) === 1;
                            const qtyVal = parseInt(itemStatus.stock_count);
                            const isOut = isTrackEnabled && qtyVal <= 0;

                            cards.forEach(card => {
                                const currentlyOut = card.classList.contains('out-of-stock');

                                if (isOut !== currentlyOut) {
                                    // Status changed! Update UI
                                    if (isOut) {
                                        card.classList.add('out-of-stock');
                                    } else {
                                        card.classList.remove('out-of-stock');
                                    }

                                    // Update Button
                                    const btn = card.querySelector('.menu-item-btn');
                                    const cartBtn = card.querySelector('.menu-cart-btn');

                                    if (btn) {
                                        btn.disabled = isOut;
                                        const span = btn.querySelector('span');
                                        if (span) span.textContent = isOut ? 'Out of Stock' : 'Order Now';

                                        // Ensure SVG is correct
                                        const existingSvg = btn.querySelector('svg');
                                        if (isOut && existingSvg) {
                                            existingSvg.remove();
                                        } else if (!isOut && !existingSvg) {
                                            btn.insertAdjacentHTML('beforeend', '<svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M6.75 13.5L11.25 9L6.75 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>');
                                        }
                                    }

                                    if (cartBtn) {
                                        cartBtn.disabled = isOut;
                                        cartBtn.style.display = isOut ? 'none' : 'flex';
                                    }
                                }
                            });
                        });
                    }
                })
                .catch(err => console.debug('Stock update skipped (networking/cooldown)'));
            }

            // Sync every 5 seconds (save server resources but keeps it feeling live)
            setInterval(updateLiveStock, 5000);
            // Run once immediately on load
            updateLiveStock();
        })();
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const timers = document.querySelectorAll('.offer-timer');

            timers.forEach(timer => {
                let seconds = parseInt(timer.dataset.seconds);
                const display = timer.querySelector('.timer-display');

                function updateTimer() {
                    if (seconds <= 0) {
                        timer.innerHTML = 'Offer Expired';
                        timer.style.color = '#9ca3af';
                        // Auto-reload to reset price
                        setTimeout(function () { location.reload(); }, 1500);
                        return;
                    }

                    const h = Math.floor(seconds / 3600);
                    const m = Math.floor((seconds % 3600) / 60);
                    const s = seconds % 60;

                    let text = '';
                    if (h > 0) text += h + 'h ';
                    if (m > 0 || h > 0) text += m + 'm ';
                    text += s + 's';

                    display.textContent = text;
                    seconds--;
                }

                updateTimer();
                setInterval(updateTimer, 1000);
            });
        });
    </script>
    <!-- Branch Selection Modal -->
    <?php include __DIR__ . '/includes/branch_selector.php'; ?>
</body>

</html>