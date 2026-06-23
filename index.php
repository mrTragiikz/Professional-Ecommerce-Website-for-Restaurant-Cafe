<?php
// Security initialization (must be first)
require_once __DIR__ . '/app/functions/security_init.php';
require_once __DIR__ . '/app/functions/auth.php';

// Get user authentication status
$isLoggedIn = isUserLoggedIn();
$user = $isLoggedIn ? getCurrentUser() : null;
// If getCurrentUser returns false (user not found in DB), treat as not logged in
if ($user === false) {
    if ($isLoggedIn) {
        error_log("DEBUG_LOGIN (index.php): User was theoretically logged in but getCurrentUser returned false. Clearing session.");
    }
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
error_log("DEBUG_LOGIN (index.php): Page loaded. Session user_id=" . ($_SESSION['user_id'] ?? 'MISSING') . " isLoggedIn=" . ($isLoggedIn ? 'true' : 'false'));

// Load operating hours settings
require_once __DIR__ . '/config/load_security.php';
$selectedBranchId = getCurrentCustomerBranchId();
$restaurantSettings = getRestaurantSettings($selectedBranchId);
$openingTime = $restaurantSettings['opening_time'] ?: '11:00';
$closingTime = $restaurantSettings['closing_time'] ?: '02:00';
$restaurantTimezone = $restaurantSettings['timezone'] ?: 'Asia/Kathmandu';
$isClosedManual = $restaurantSettings['is_closed'];

// Fetch Top Dishes for showcase
$topDishes = [];
try {
    require_once __DIR__ . '/config/db.php';
    // We'll fetch specific items that match the showcase
    $showcaseNames = ['Steam Mo:Mo (Chicken)', 'Veg Handi Biryani', 'Chicken Chilli', 'Butter Chicken'];

    // Check if there are active offers to override/prioritize?
// For now, let's fetch specific items BUT also include any item that has an active offer (old_price > price)
// Actually, to be safe and keep design, let's just fetch the hardcoded ones but enable offer display.

    $placeholders = str_repeat('?,', count($showcaseNames) - 1) . '?';
    // Optimized query: Select only what is needed for the frontend display
    if (isset($pdo) && $pdo !== null) {
        $selectedBranchId = getCurrentCustomerBranchId();
        $stmt = $pdo->prepare("
SELECT
m.item_name,
m.item_description,
m.price,
m.old_price,
m.offer_end_time,
m.image_path as img,
m.stock_count,
c.category_key,
c.category_name
FROM menu_items m
JOIN menu_categories c ON m.category_id = c.id
WHERE m.item_name IN ($placeholders) AND m.restaurant_id = ?
");
        $params = array_merge($showcaseNames, [$selectedBranchId]);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Map results by name for easy access in HTML
        foreach ($results as $item) {
            $topDishes[$item['item_name']] = $item;
        }
    }
} catch (Throwable $e) {
    error_log("Home page top dishes error: " . $e->getMessage());
}
// Fetch active branches for the Branch Picker Modal
$branches = [];
try {
    if (isset($pdo) && $pdo !== null) {
        $stmt = $pdo->query("SELECT id, name, address, phone FROM branches WHERE is_active = 1 ORDER BY name ASC");
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log("Error fetching branches: " . $e->getMessage());
}

// Current branch from session (already set above)
?>
<!DOCTYPE html>
<html lang="en">
<?php
/**
 * JustKleek - Home Page
 */
?>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>JustKleek | Home</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link href="https://fonts.bunny.net/css2?family=Inter:wght@400;500&family=Poppins:wght@600;700&display=swap"
        rel="stylesheet">
    <link rel="preload" href="<?php echo $basePath; ?>/css/style.css?v=<?php echo time(); ?>" as="style">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/style.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/assets/css/dv_mobile_nav_v3.css?v=<?php echo time(); ?>">

    <style>
        /* Scene Animations */

        /* Scooter Bubble */
        .dv-scene-bubble {
            position: absolute;
            background: #ffffff;
            padding: 8px 12px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 700;
            color: #1E1E1E;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            white-space: nowrap;
            z-index: 1000;
            visibility: visible;
            display: block;
            opacity: 1;
            transform-origin: center bottom;
            border: 1px solid rgba(0, 0, 0, 0.1);
        }

        .dv-scene-bubble--scooter {
            top: -50px;
            left: 50%;
            transform: translateX(-50%);
            animation: fadeIn 0.5s ease-out forwards, dvBubbleBob 2s ease-in-out infinite;
        }

        .dv-scene-bubble--scooter::after {
            content: '';
            position: absolute;
            bottom: -6px;
            left: 50%;
            transform: translateX(-50%);
            border-width: 6px 6px 0;
            border-style: solid;
            border-color: #ffffff transparent transparent transparent;
        }

        /* Keyframes */
    </style>
    <style>
        /* Essential fix for bubble positioning */
        .trust-icon-box {
            position: relative !important;
            overflow: visible !important;
        }

        @keyframes dvBubbleBob {

            0%,
            100% {
                transform: translateX(-50%) translateY(0);
            }

            50% {
                transform: translateX(-50%) translateY(-5px);
            }
        }

        /* Cinematic Text Animation */
        .cinematic-text {
            opacity: 0;
            transform: translateX(-50px);
            /* Come from left side */
            transition: opacity 1.2s ease-out, transform 1.2s ease-out;
            will-change: opacity, transform;
        }

        .cinematic-text.in-view {
            opacity: 1;
            transform: translateX(0);
        }

        /* Cinematic Slide In From Right */
        .cinematic-slide-in-right {
            opacity: 0;
            transform: translateX(60px);
            transition: opacity 1.6s cubic-bezier(0.22, 1, 0.36, 1), transform 1.6s cubic-bezier(0.22, 1, 0.36, 1);
            will-change: opacity, transform;
        }

        .cinematic-slide-in-right.in-view {
            opacity: 1;
            transform: translateX(0);
        }

        /* Cinematic Slide In From Left */
        .cinematic-slide-in-left {
            opacity: 0;
            transform: translateX(-60px);
            transition: opacity 1.6s cubic-bezier(0.22, 1, 0.36, 1), transform 1.6s cubic-bezier(0.22, 1, 0.36, 1);
            will-change: opacity, transform;
        }

        .cinematic-slide-in-left.in-view {
            opacity: 1;
            transform: translateX(0);
        }

        /* Cinematic Fade Up */
        .cinematic-fade-up {
            opacity: 0;
            transform: translateY(40px);
            transition: opacity 1.0s ease-out, transform 1.0s cubic-bezier(0.22, 1, 0.36, 1);
            will-change: opacity, transform;
        }

        .cinematic-fade-up.in-view {
            opacity: 1;
            transform: translateY(0);
        }
    </style>
    <script src="<?php echo $basePath; ?>/js/prevent-zoom.js?v=1.0.1"></script>
    <script src="<?php echo $basePath; ?>/js/click-sound.js?v=1.0.1"></script>
    <?php require_once __DIR__ . '/includes/meta_pixel.php'; ?>
    <style>
    /* Branch selector styles */
    /* DELETED: Branch selector CSS now handled in includes/branch_selector.php */
    </style>
</head>

<body class="home-page">
    <!-- Navigation Bar -->
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>


    <style>
        @media (max-width: 768px) {
            .hero-left {
                padding-top: 120px !important;
            }

            .hero-trust-badges {
                margin-top: 100px !important;
            }
        }



        @keyframes waveBreathe {

            0%,
            100% {
                transform: scale(1) rotate(0deg);
            }

            25% {
                transform: scale(1.1) rotate(8deg);
            }

            50% {
                transform: scale(1.15) rotate(-5deg);
            }

            75% {
                transform: scale(1.1) rotate(4deg);
            }
        }
    </style>
    <main class="hero">
        <div class="hero-container" style="position: relative; overflow: hidden;">
            <!-- Hero Video Background (Optimized for smoothness and fast loading) -->
            <video autoplay loop muted playsinline preload="auto" disablePictureInPicture aria-hidden="true"
                style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; z-index: 0; background-color: #121212; transform: translate3d(0, 0, 0); backface-visibility: hidden; perspective: 1000px; will-change: transform;">
                <source src="assets/background.mp4" type="video/mp4">
            </video>

            <div class="hero-overlay"
                style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1;">
            </div>

            <div class="hero-left" style="position: relative; z-index: 2;">
                <span class="hero-badge fade-diag">WELCOME TO JUSTKLEEK</span>
                <h1 class="hero-title" style="color: #ffffff;">
                    Hot, Fresh &<br>
                    <span class="hero-accent">Delivered Fast</span>
                </h1>
                <!-- Tagline Line (Small Text) replaces trust badges -->
                <div class="hero-trust-badges fade-diag" style="--delay:0.12s;">
                    <div class="trust-item" style="width: 100%;">
                        <div class="trust-info" style="flex-direction: row; gap: 12px; align-items: center;">
                            <span class="trust-label"
                                style="font-size: 13px; font-weight: 600; color: #ffffff; display: inline-flex; align-items: center; gap: 5px;">
                                <span
                                    style="display: inline-block; animation: waveBreathe 2.5s ease-in-out infinite; transform-origin: bottom center; font-size: 15px;"></span>
                                Fast Home Delivery
                            </span>
                            <span style="color: #ccc;">•</span>
                            <span class="trust-label"
                                style="font-size: 13px; font-weight: 600; color: #ffffff; display: inline-flex; align-items: center; gap: 5px;">
                                <span
                                    style="display: inline-block; animation: waveBreathe 2.5s ease-in-out infinite 0.4s; transform-origin: bottom center; font-size: 15px;"></span>
                                Quality Ingredients
                            </span>
                            <span style="color: #ccc;">•</span>
                            <span class="trust-label"
                                style="font-size: 13px; font-weight: 600; color: #ffffff; display: inline-flex; align-items: center; gap: 5px;">
                                <span
                                    style="display: inline-block; animation: waveBreathe 2.5s ease-in-out infinite 0.8s; transform-origin: bottom center; font-size: 15px;"></span>
                                Open Till Late
                            </span>
                        </div>
                    </div>
                </div>

                <div class="hero-buttons fade-diag" style="--delay:0.15s;">
                    <a class="hero-cta hero-cta--menu" href="menu">Order Now</a>
                </div>

            </div>
            <div class="hero-right" style="position: relative; z-index: 2;">
                <!--        Time Counting Section -->


                <!-- Orange splash background -->
                <div class="hero-splash"></div>

            </div>
        </div>
    </main>

    <!-- Commitment & Values Section -->
    <?php include __DIR__ . '/includes/section_commitment.php'; ?>

    <!-- Himalayan Momos Section -->
    <!-- Himalayan Momos Section -->
    <style>
        @keyframes specialRgbShift {
            0% {
                background-position: 0% 50%;
            }

            50% {
                background-position: 100% 50%;
            }

            100% {
                background-position: 0% 50%;
            }
        }

        .momos-special-bg {
            background: linear-gradient(-45deg,
                    #FFFAF0, #FFF9F2, #FDF8F0,
                    #FCFBF7, #FFF5E6, #FEFBF3,
                    #FFFDF9, #FBF8F0, #FFFBF5);
            background-size: 400% 400%;
            animation: specialRgbShift 12s ease infinite;
            position: relative;
            overflow: hidden;
        }

        .momos-special-bg .momos-section__title,
        .momos-special-bg .momos-section__text p {
            color: #050505 !important;
            /* Sharp, deep black */
            text-shadow: 0 0 1px rgba(255, 255, 255, 0.3);
            /* Subtle outline for readability on dark parts */
        }
    </style>
    <section class="momos-section momos-special-bg">
        <!-- Decorative subtle pattern overlay -->
        <div
            style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background-image: radial-gradient(#E31837 0.5px, transparent 0.5px); background-size: 20px 20px; opacity: 0.03; pointer-events: none;">
        </div>
        <div class="momos-section__container">
            <div class="momos-section__content">
                <div class="momos-section__badge badge-float">Specialty</div>
                <h2 class="momos-section__title">Royal Matka <span style="color: #E31837;">Biryani</span></h2>
                <div class="momos-section__text">
                    <p class="cinematic-text" style="transition-delay: 0.1s;">Biryani has evolved over centuries, shaped
                        by culture, tradition, and regional flavors. From <span class="word-highlight">royal
                            kitchens</span> to modern dining tables, it has
                        always been known for its layered rice, rich spices, and careful cooking process.</p>
                    <p class="cinematic-text" style="transition-delay: 0.3s;">Our Matka Biryani is prepared using the
                        traditional <span class="word-highlight gold">dum method</span>, slow-cooked inside a sealed
                        clay pot to lock in aroma and flavor.
                        Long-grain basmati rice blends perfectly with tender marinated meat and balanced spices,
                        creating a deep and satisfying taste that feels <span class="word-highlight red">ekdam
                            mitho</span>.</p>
                    <p class="cinematic-text" style="transition-delay: 0.5s;">At <span
                            class="gradient-pulse-text">JustKleek</span>, we focus on quality
                        ingredients and authentic preparation, serving fresh and flavorful Matka Biryani made with
                        patience and care.</p>
                </div>
                <a href="menu" class="momos-section__button cinematic-text" style="transition-delay: 0.7s;">Menu</a>
            </div>
            <div class="momos-section__image-wrapper">
                <style>
                    .momos-rotating-plate {
                        max-width: 380px;
                        width: 100%;
                        height: auto;
                        border-radius: 50%;
                        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
                        animation: spinPlate 60s linear infinite;
                        margin: 0 auto;
                        display: block;
                    }

                    @keyframes spinPlate {
                        from {
                            transform: scale(1.35) rotate(0deg);
                        }

                        to {
                            transform: scale(1.35) rotate(360deg);
                        }
                    }

                    @media (max-width: 768px) {
                        .momos-rotating-plate {
                            max-width: 260px;
                        }

                        .momos-section__content {
                            padding-top: 25px;
                        }

                        .momos-section__badge {
                            margin-top: 20px !important;
                        }
                    }

                    .gradient-pulse-text {
                        font-weight: 900;
                        /* Dark, rich, and deeply colorful gradient */
                        background: linear-gradient(-45deg, #8B0000, #4B0082, #00008B, #004600, #660066, #8B0000);
                        background-size: 300% 300%;
                        -webkit-background-clip: text;
                        background-clip: text;
                        -webkit-text-fill-color: transparent;
                        display: inline-block;
                        animation: gradientFlow 4s linear infinite;
                        filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.3));
                    }

                    @keyframes gradientFlow {
                        0% {
                            background-position: 0% 50%;
                        }

                        100% {
                            background-position: 300% 50%;
                        }
                    }

                    .word-highlight {
                        position: relative;
                        display: inline-block;
                        font-weight: 700;
                        color: #333;
                        transition: transform 0.3s ease;
                    }

                    .word-highlight::after {
                        content: '';
                        position: absolute;
                        bottom: 0;
                        left: 0;
                        width: 0%;
                        height: 2px;
                        background: #E31837;
                        transition: width 0.5s ease;
                    }

                    .momos-section__content:hover .word-highlight::after {
                        width: 100%;
                    }

                    .word-highlight:hover {
                        transform: translateY(-2px);
                        color: #E31837;
                    }

                    .word-highlight.gold {
                        color: #b45309;
                    }

                    .word-highlight.gold::after {
                        background: #f59e0b;
                    }

                    .word-highlight.gold:hover {
                        color: #d97706;
                    }

                    .word-highlight.red {
                        color: #dc2626;
                    }

                    .badge-float {
                        animation: gentleFloat 3s ease-in-out infinite;
                    }

                    @keyframes gentleFloat {

                        0%,
                        100% {
                            transform: translateY(0);
                        }

                        50% {
                            transform: translateY(-5px);
                        }
                    }
                </style>
                <!-- Rotating plate animation -->
                <img src="<?php echo $basePath; ?>/assets/briyani.png" alt="Royal Matka Biryani"
                    class="momos-rotating-plate cinematic-slide-in-right">
            </div>
        </div>
    </section>

    <!-- Top Dishes Section -->
    <style>
        @keyframes topDishesBgShift {
            0% {
                background-position: 0% 50%;
            }

            50% {
                background-position: 100% 50%;
            }

            100% {
                background-position: 0% 50%;
            }
        }

        @keyframes rgbTextFlow {
            0% {
                color: #E31837;
            }

            /* Brand Red */
            25% {
                color: #8b5cf6;
            }

            /* Violet */
            50% {
                color: #3b82f6;
            }

            /* Blue */
            75% {
                color: #10b981;
            }

            /* Emerald */
            100% {
                color: #E31837;
            }

            /* Brand Red */
        }

        .top-dishes-animated-bg {
            /* Clean, professional, creamy-white aesthetic */
            background: linear-gradient(-45deg, #FFFFFF, #FCFBF9, #F9FAFB, #FCFBF9);
            background-size: 400% 400%;
            animation: topDishesBgShift 20s ease infinite;
        }

        @keyframes titleBreathing {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.05);
            }

            100% {
                transform: scale(1);
            }
        }

        /* Override specifically for this section's title - Professional Look */
        #topDishesSection .top-dishes__title {
            color: #0f172a;
            /* Premium Dark Black */
            font-weight: 900;
            /* Extra Bold */
            letter-spacing: -0.5px;
            text-transform: capitalize;
            text-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            display: inline-block;
            /* required for scale transformation */
            animation: titleBreathing 4s ease-in-out infinite !important;
        }

        @keyframes cardBreathing {
            0% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.02);
            }

            100% {
                transform: scale(1);
            }
        }

        #topDishesSection .menu-card {
            transition: all 0.3s ease;
            /* Keep transition for hover effects if any */
            animation: cardBreathing 3s ease-in-out infinite;
        }

        /* Stagger animations for a more organic feel */
        #topDishesSection .menu-card:nth-child(1) {
            animation-delay: 0s;
        }

        #topDishesSection .menu-card:nth-child(2) {
            animation-delay: 0.5s;
        }

        #topDishesSection .menu-card:nth-child(3) {
            animation-delay: 1s;
        }

        #topDishesSection .menu-card:nth-child(4) {
            animation-delay: 1.5s;
        }

        /* Out of Stock Styling */
        .menu-card.out-of-stock {
            opacity: 0.8 !important;
            filter: grayscale(0.5);
            cursor: not-allowed;
            pointer-events: none;
            /* Prevent clicks on out of stock items */
        }

        .menu-card.out-of-stock .menu-card-image::after {
            content: 'OUT OF STOCK';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-15deg);
            background: rgba(227, 10, 10, 0.9);
            color: white;
            padding: 5px 15px;
            font-weight: 800;
            font-size: 14px;
            border-radius: 4px;
            letter-spacing: 1px;
            white-space: nowrap;
            z-index: 100;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .menu-card.out-of-stock .menu-item-btn {
            background: #94a3b8 !important;
            color: white !important;
            border: none !important;
        }

        @keyframes funnySlidePass {
            0% {
                transform: translateX(-120vw) skewX(-20deg);
                opacity: 0;
            }

            15% {
                transform: translateX(0) skewX(0deg);
                opacity: 1;
            }

            /* Wiggle/Funny phase in center */
            25% {
                transform: translateX(0) rotate(5deg) scale(1.1);
            }

            35% {
                transform: translateX(0) rotate(-5deg) scale(1.1);
            }

            45% {
                transform: translateX(0) rotate(5deg) scale(1.1);
            }

            55% {
                transform: translateX(0) rotate(-5deg) scale(1.1);
            }

            65% {
                transform: translateX(0) rotate(0) scale(1);
            }

            /* Exit phase */
            85% {
                transform: translateX(120vw) skewX(20deg);
                opacity: 1;
            }

            100% {
                transform: translateX(120vw);
                opacity: 0;
            }
        }

        #topDishesSection .top-dishes__subtitle {
            color: #64748b !important;
            /* Professional slate gray instead of harsh black */
            font-weight: 500;
            margin-top: 8px;
            font-size: 1.1rem;
            display: inline-block;
        }

        @media (max-width: 768px) {
            #topDishesSection .top-dishes__subtitle {
                font-size: 0.95rem !important;
                /* Smaller on mobile to prevent cropping */
                white-space: nowrap;
            }
        }
    </style>
    <section class="top-dishes top-dishes-animated-bg" id="topDishesSection">
        <?php
        $activeCombos = [];
        $currentBranchNameForCombos = null;
        if (!empty($branches) && !empty($selectedBranchId)) {
            foreach ($branches as $b) {
                if ((int) ($b['id'] ?? 0) === (int) $selectedBranchId) {
                    $currentBranchNameForCombos = $b['name'] ?? null;
                    break;
                }
            }
        }
        try {
            if (isset($pdo) && $pdo !== null) {
                // Find Combo Category ID
                $catStmt = $pdo->prepare("SELECT id FROM menu_categories WHERE category_name = 'Combo Offers' AND restaurant_id = ?");
                $catStmt->execute([$selectedBranchId]);
                $comboCat = $catStmt->fetch();

                if ($comboCat) {
                    // Fetch active items from this category
                    $combosStmt = $pdo->prepare("SELECT * FROM menu_items WHERE category_id = ? AND is_active = 1 AND restaurant_id = ? ORDER BY id DESC LIMIT 8");
                    $combosStmt->execute([$comboCat['id'], $selectedBranchId]);
                    $activeCombos = $combosStmt->fetchAll();
                }
            }
        } catch (Throwable $e) {
            // Fallback
        }

        if (count($activeCombos) > 0): ?>
            <div class="top-dishes__container">
                <div class="top-dishes__header">
                    <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap;">
                        <div>
                            <h2 class="top-dishes__title">Combo Offers</h2>
                            <div style="padding: 20px 0 0 0;">
                                <p class="top-dishes__subtitle">Don't miss out on these limited time deals!</p>
                            </div>
                        </div>

                        <div style="display:flex; align-items:center; gap:12px; padding: 10px 16px; background: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 999px; box-shadow: 0 4px 15px -5px rgba(0,0,0,0.08);">
                            <div style="font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 1px; display: flex; align-items: center; gap: 6px; padding-right: 4px;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                Branch
                            </div>
                            <div style="font-weight: 800; color: #0f172a; font-size: 14px; white-space: nowrap; border-left: 1px solid #e2e8f0; padding-left: 12px;">
                                <?php echo htmlspecialchars($currentBranchNameForCombos ?: ('Branch #' . (int) $selectedBranchId)); ?>
                            </div>
                            <?php if (!$isLoggedIn): ?>
                            <button type="button"
                                onclick="if (window.openBranchModal) { window.openBranchModal(); }"
                                style="border:none; background: #111827; color: #fff; font-weight: 900; font-size: 11px; padding: 6px 16px; border-radius: 999px; cursor: pointer; white-space: nowrap; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); text-transform: uppercase; letter-spacing: 0.5px; margin-left: 4px;"
                                onmouseover="this.style.background='#000'; this.style.transform='translateY(-1px)';"
                                onmouseout="this.style.background='#111827'; this.style.transform='translateY(0)';"
                                onmousedown="this.style.transform='translateY(1px) scale(0.98)';">
                                Change
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="padding: 20px 0;">
                        <!-- spacing kept for layout consistency -->
                    </div>
                </div>

                <div class="top-dishes__grid">
                    <?php foreach ($activeCombos as $index => $item):
                        $delay = $index * 0.2;
                        $side = ($index < 2) ? 'left' : 'right';

                        // Price Logic
                        $priceHtml = 'Rs. ' . number_format($item['price']);
                        if (!empty($item['old_price']) && $item['old_price'] != $item['price']) {
                            $priceHtml = '<span style="color: #E31837; font-weight:800; margin-right:8px;">Rs. ' . number_format($item['price']) . '</span>' .
                                '<span style="text-decoration: line-through; color: #000000; font-size:0.9em;">Rs. ' . number_format($item['old_price']) . '</span>';
                        }

                        // Timer logic
                        $remainingSeconds = 0;
                        if (!empty($item['offer_end_time']) && strtotime($item['offer_end_time']) > time()) {
                            $remainingSeconds = strtotime($item['offer_end_time']) - time();
                        }
                        ?>
                        <div class="menu-card cinematic-slide-in-<?php echo $side; ?>"
                            style="transition-delay: <?php echo $delay; ?>s;"
                            data-item-name="<?php echo htmlspecialchars($item['item_name']); ?>"
                            data-display-name="<?php echo htmlspecialchars($item['item_name']); ?>"
                            data-display-description="<?php echo htmlspecialchars($item['item_description']); ?>">

                            <div class="menu-card-image" style="position: relative;">
                                <?php if (!empty($item['old_price']) && $item['old_price'] != $item['price']):
                                    $maxPrice = max($item['old_price'], $item['price']);
                                    $minPrice = min($item['old_price'], $item['price']);
                                    $off = round((($maxPrice - $minPrice) / $maxPrice) * 100); ?>
                                    <div class="discount-badge"
                                        style="position: absolute; top: 10px; left: 10px; background: #E31837; color: white; padding: 4px 8px; border-radius: 4px; font-weight: 800; font-size: 0.85rem; z-index: 10;">
                                        <?php echo $off; ?>%<small style="font-size: 10px; margin-left: 2px;">OFF</small>
                                    </div>
                                <?php endif; ?>
                                <?php
                                $imgSrc = !empty($item['image_path']) ? ($basePath . '/' . htmlspecialchars($item['image_path'])) : ($basePath . '/assets/logo.png');
                                ?>
                                <img src="<?php echo $imgSrc; ?>?v=<?php echo time(); ?>" alt="<?php echo htmlspecialchars($item['item_name']); ?>"
                                    style="<?php echo empty($item['image_path']) ? 'object-fit: contain; padding: 20px;' : 'object-fit: cover;'; ?>">
                            </div>

                            <div class="menu-card-content">
                                <h3 class="menu-item-name"><?php echo htmlspecialchars($item['item_name']); ?></h3>
                                <p class="menu-item-description"><?php echo htmlspecialchars($item['item_description']); ?></p>
                                <div class="menu-item-price">
                                    <?php echo $priceHtml; ?>
                                    <?php if ($remainingSeconds > 0): ?>
                                        <div class="offer-timer" data-seconds="<?php echo $remainingSeconds; ?>"
                                            style="color: #ea580c; font-size: 0.85rem; font-weight: 600; margin-top: 4px; display: flex; align-items: center; justify-content: center; gap: 4px;">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                <circle cx="12" cy="12" r="10"></circle>
                                                <polyline points="12 6 12 12 16 14"></polyline>
                                            </svg>
                                            <span>Ends in: <span class="timer-display"></span></span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="menu-card-footer">
                                    <button class="menu-item-btn">
                                        <span>Order Now</span>
                                        <svg width="18" height="18" viewBox="0 0 18 18" fill="none">
                                            <path d="M6.75 13.5L11.25 9L6.75 4.5" stroke="currentColor" stroke-width="2"
                                                stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </button>
                                    <button class="menu-cart-btn" aria-label="Add to cart">
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
                                            <path d="M5 7L4 3H2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                                stroke-linejoin="round" />
                                        </svg>
                                        <span>Cart</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="top-dishes__footer">
                    <a href="menu" class="top-dishes__button">See Full Menu</a>
                </div>
            </div>
        <?php else: ?>
            <div class="coming-soon-container">
                <div class="coming-soon-wrapper">
                    <div class="coming-soon-glow-bg"></div>
                    <div class="coming-soon-card">
                        <div class="logo-container">
                            <div class="logo-aura"></div>
                            <img src="<?php echo $basePath; ?>/assets/frontlogo.jpg" alt="JustKleek" class="animated-logo">
                        </div>
                        <div class="coming-soon-content">
                            <h2 class="shimmer-text">Currently No Combo Available</h2>
                            <p>We're cooking up some great new combo deals.<br>Please check back soon!</p>
                            <div class="coming-soon-badge" style="margin-bottom: 25px;">
                                <span class="badge-dot"></span>
                                STAY TUNED
                            </div>

                            <!-- Branch Selection Button for "No Combo" State -->
                            <div style="margin-top: 10px; display: flex; flex-direction: column; align-items: center; gap: 12px; border-top: 1px solid rgba(0,0,0,0.05); padding-top: 20px;">
                                <div style="font-size: 13px; font-weight: 700; color: #64748b; letter-spacing: 0.5px;">
                                    Current Branch: <span style="color: #E31837; font-weight: 800;"><?php echo htmlspecialchars($currentBranchNameForCombos ?: ('Branch #' . (int) $selectedBranchId)); ?></span>
                                </div>
                                <?php if (!$isLoggedIn): ?>
                                <button type="button" 
                                    onclick="if (window.openBranchModal) { window.openBranchModal(); }"
                                    style="background: #111827; color: #ffffff; border: none; font-weight: 800; font-size: 12px; padding: 12px 28px; border-radius: 100px; cursor: pointer; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); letter-spacing: 1px; box-shadow: 0 10px 20px -5px rgba(17, 24, 39, 0.3); display: inline-flex; align-items: center; gap: 8px; text-transform: uppercase;"
                                    onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 15px 25px -5px rgba(17, 24, 39, 0.4)';"
                                    onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 10px 20px -5px rgba(17, 24, 39, 0.3)';"
                                    onmousedown="this.style.transform='translateY(-1px) scale(0.98)';">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                    Switch Branch
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <style>
                .coming-soon-container {
                    padding: 100px 20px;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    background: linear-gradient(135deg, #f0f4f8 0%, #ffffff 100%);
                    position: relative;
                    z-index: 1;
                }

                .coming-soon-wrapper {
                    position: relative;
                    max-width: 600px;
                    width: 100%;
                }

                /* Animated Blur Background Glow */
                .coming-soon-glow-bg {
                    position: absolute;
                    top: -20px;
                    left: -20px;
                    right: -20px;
                    bottom: -20px;
                    background: linear-gradient(45deg, #E31837, #ea580c, #f59e0b, #E31837);
                    background-size: 400% 400%;
                    border-radius: 35px;
                    z-index: -1;
                    filter: blur(40px);
                    opacity: 0.18;
                    animation: ultraGlowFlow 10s ease infinite;
                }

                @keyframes ultraGlowFlow {
                    0% {
                        background-position: 0% 50%;
                        opacity: 0.25;
                    }

                    50% {
                        background-position: 100% 50%;
                        opacity: 0.15;
                    }

                    100% {
                        background-position: 0% 50%;
                        opacity: 0.25;
                    }
                }

                .coming-soon-card {
                    background: rgba(255, 255, 255, 0.85);
                    backdrop-filter: blur(25px);
                    -webkit-backdrop-filter: blur(25px);
                    padding: 50px 60px;
                    border-radius: 30px;
                    box-shadow: 0 40px 80px -20px rgba(0, 0, 0, 0.1), inset 0 0 0 1px rgba(255, 255, 255, 1);
                    text-align: center;
                    position: relative;
                    overflow: hidden;
                    border: 1px solid rgba(255, 255, 255, 0.5);
                    transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
                }

                .coming-soon-card:hover {
                    transform: translateY(-8px);
                }

                .coming-soon-card::before {
                    content: '';
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    height: 5px;
                    background: linear-gradient(90deg, #E31837, #ea580c, #f59e0b, #ea580c, #E31837);
                    background-size: 300% 100%;
                    animation: gradientMove 3s linear infinite;
                }

                .logo-container {
                    margin-bottom: 35px;
                    display: flex;
                    justify-content: center;
                    position: relative;
                }

                .logo-aura {
                    position: absolute;
                    top: 50%;
                    left: 50%;
                    width: 110px;
                    height: 110px;
                    transform: translate(-50%, -50%);
                    background: linear-gradient(135deg, #E31837, #f59e0b);
                    border-radius: 50%;
                    filter: blur(20px);
                    opacity: 0.4;
                    animation: auraPulse 4s ease-in-out infinite alternate;
                }

                @keyframes auraPulse {
                    0% {
                        transform: translate(-50%, -50%) scale(1);
                        opacity: 0.3;
                    }

                    100% {
                        transform: translate(-50%, -50%) scale(1.3);
                        opacity: 0.6;
                    }
                }

                .animated-logo {
                    width: 90px;
                    height: 90px;
                    border-radius: 50%;
                    object-fit: cover;
                    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15), 0 0 0 6px #ffffff;
                    animation: premiumBounce 4s cubic-bezier(0.28, 0.84, 0.42, 1) infinite;
                    position: relative;
                    z-index: 2;
                    background: #fff;
                }

                @keyframes premiumBounce {

                    0%,
                    100% {
                        transform: translateY(0) scale(1.0);
                    }

                    50% {
                        transform: translateY(-12px) scale(1.05);
                    }
                }

                .shimmer-text {
                    font-size: 2.2rem;
                    margin-bottom: 16px;
                    font-weight: 900;
                    letter-spacing: -0.8px;
                    background: linear-gradient(to right, #111827 0%, #374151 30%, #E31837 50%, #ea580c 60%, #374151 80%, #111827 100%);
                    background-size: 200% auto;
                    color: transparent;
                    -webkit-background-clip: text;
                    background-clip: text;
                    animation: premiumShimmer 5s linear infinite;
                    line-height: 1.2;
                }

                @keyframes premiumShimmer {
                    0% {
                        background-position: -200% center;
                    }

                    100% {
                        background-position: 200% center;
                    }
                }

                .coming-soon-content p {
                    font-size: 1.15rem;
                    color: #4b5563;
                    line-height: 1.7;
                    margin-bottom: 30px;
                    font-weight: 500;
                }

                .coming-soon-badge {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    gap: 8px;
                    padding: 10px 24px;
                    background: linear-gradient(135deg, #111827, #374151);
                    color: #ffffff;
                    font-size: 0.9rem;
                    font-weight: 800;
                    letter-spacing: 1.5px;
                    border-radius: 100px;
                    text-transform: uppercase;
                    box-shadow: 0 10px 20px -5px rgba(17, 24, 39, 0.4);
                    border: 1px solid rgba(255, 255, 255, 0.1);
                    transition: transform 0.3s ease, box-shadow 0.3s ease;
                }

                .coming-soon-badge:hover {
                    transform: scale(1.05);
                    box-shadow: 0 15px 30px -5px rgba(17, 24, 39, 0.5);
                }

                .badge-dot {
                    width: 8px;
                    height: 8px;
                    background-color: #f59e0b;
                    border-radius: 50%;
                    display: inline-block;
                    box-shadow: 0 0 10px #f59e0b;
                    animation: dotPulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
                }

                @keyframes dotPulse {

                    0%,
                    100% {
                        opacity: 1;
                        transform: scale(1);
                    }

                    50% {
                        opacity: 0.4;
                        transform: scale(0.8);
                    }
                }

                @keyframes gradientMove {
                    0% {
                        background-position: 0% 0%;
                    }

                    100% {
                        background-position: 200% 0%;
                    }
                }

                @media (max-width: 600px) {
                    .coming-soon-card {
                        padding: 30px 20px;
                    }

                    .shimmer-text {
                        font-size: 1.5rem;
                    }
                }
            </style>
        <?php endif; ?>
    </section>

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
                        // Auto-reload to reset price in backend and frontend
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

    <!-- Cinematic Compact Slideshow Gallery -->
    <section class="dv-slideshow-section" id="gallery">
        <div class="dv-slideshow-container">
            <div class="dv-slideshow-header cinematic-fade-up" style="transition-delay: 0s;">
                <span class="dv-slideshow-badge">GALLERY</span>
                <h2 class="dv-slideshow-title">Our Beautiful Moments</h2>
                <style>
                    .gallery-anim-container {
                        position: relative;
                        display: flex;
                        justify-content: center;
                        align-items: center;
                        height: 60px;
                        overflow: visible;
                    }

                    .gallery-text {
                        font-family: 'Poppins', sans-serif;
                        font-size: 1.5rem;
                        font-weight: 700;
                        color: #475569;
                        position: relative;
                        z-index: 1;
                        white-space: nowrap;
                        animation: blastSequence 10s cubic-bezier(0.4, 0, 0.2, 1) infinite;
                    }

                    /* 
                       BLAST SEQUENCE 
                       0-20%: Normal
                       20-22%: Shake
                       22-25%: EXPLOSION!
                       25-45%: Gone/Dust
                       45-70%: Rebuilding (Magical)
                       70-90%: Fixed & Shiny
                       90-100%: Reset
                    */
                    @keyframes blastSequence {

                        0%,
                        20% {
                            transform: scale(1);
                            filter: blur(0);
                            opacity: 1;
                            letter-spacing: normal;
                            color: #475569;
                        }

                        21% {
                            transform: translate(-3px, 3px) rotate(-3deg);
                            color: #dc2626;
                        }

                        22% {
                            transform: translate(3px, -3px) rotate(3deg);
                        }

                        23% {
                            transform: scale(1.5) rotate(10deg);
                            filter: blur(10px);
                            opacity: 0;
                            letter-spacing: 20px;
                            color: #ef4444;
                        }

                        24%,
                        49% {
                            /* DUST STATE */
                            transform: scale(0.5);
                            filter: blur(20px);
                            opacity: 0;
                            letter-spacing: 50px;
                        }

                        50% {
                            /* START REBUILDING (Magical formation) */
                            transform: scale(0.8);
                            filter: blur(8px);
                            opacity: 0;
                            letter-spacing: 10px;
                            color: #3b82f6;
                            /* Blue glow */
                        }

                        60% {
                            /* HALFWAY */
                            transform: scale(0.95);
                            filter: blur(2px);
                            opacity: 0.8;
                            letter-spacing: 2px;
                            color: #3b82f6;
                        }

                        70% {
                            /* FIXED */
                            transform: scale(1);
                            filter: blur(0);
                            opacity: 1;
                            letter-spacing: normal;
                            color: #166534;
                            /* Green/Success */
                        }

                        90% {
                            transform: scale(1);
                        }

                        100% {
                            color: #475569;
                        }
                    }
                </style>
                <div class="gallery-anim-container">
                    <div class="gallery-text">Building Beautiful Memories</div>
                </div>
            </div>

            <div class="dv-slideshow-viewport cinematic-fade-up" style="transition-delay: 0.2s;">
                <div class="dv-slideshow-track" id="galleryTrack">
                    <?php
                    // Dynamic Gallery Loading
                    $galleryDir = __DIR__ . '/uploads/gallery/';
                    $displayImages = [];

                    // Check for uploaded images
                    if (is_dir($galleryDir)) {
                        // Use a simple glob and filter, as GLOB_BRACE can be unreliable on some systems
                        $files = glob($galleryDir . '*.*');
                        if ($files) {
                            // Filter for images
                            $files = array_filter($files, function ($f) {
                                return preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $f);
                            });

                            // Sort by newest first
                            usort($files, function ($a, $b) {
                                return filemtime($b) - filemtime($a);
                            });

                            foreach ($files as $f) {
                                // Add cache busting parameter to prevent caching issues
                                $displayImages[] = 'uploads/gallery/' . basename($f) . '?v=' . filemtime($f);
                            }
                        }
                    }

                    // Fallback to defaults if no uploads (removed broken path references)
                    if (empty($displayImages)) {
                        /* 
                           Fallback images directory 'assets/vatti gallery/' was not found.
                           Please upload images via admin panel.
                        */
                    }
                    ?>

                    <?php if (!empty($displayImages)): ?>
                        <?php foreach ($displayImages as $index => $imgSrc): ?>
                            <?php
                            // Use relative path for robustness on index.php
                            // Encode filename parts but keep structure
                            // $imgSrc is already 'uploads/gallery/filename?v=...'
                            // We just need to ensure spaces are encoded if any
                            $urlSrc = str_replace(' ', '%20', $imgSrc);
                            ?>
                            <div class="dv-slide <?php echo $index === 0 ? 'active' : ''; ?>">
                                <img src="<?php echo $urlSrc; ?>" alt="Gallery Image <?php echo $index + 1; ?>" loading="lazy">
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- No images available -->
                        <div class="dv-slide active">
                            <div
                                style="height: 100%; display: flex; align-items: center; justify-content: center; background: #f8fafc; color: #64748b;">
                                No images available
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Controls -->
                <button class="dv-control prev" onclick="moveSlide(-1)">&#10094;</button>
                <button class="dv-control next" onclick="moveSlide(1)">&#10095;</button>

                <div class="dv-dots" id="galleryDots">
                    <!-- Dots generated by JS -->
                </div>
            </div>
        </div>
    </section>

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

            <!-- Delivery/Pickup Toggle -->
            <div class="modal-service-toggle">
                <button class="modal-service-btn" id="modalDeliveryBtn" data-service="delivery">Delivery</button>
                <button class="modal-service-btn active" id="modalPickupBtn" data-service="pickup">Pickup</button>
            </div>

            <!-- Delivery Location (shown when Delivery is selected) -->
            <div class="modal-delivery-location" id="modalDeliveryLocation" style="display: none;">
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

            <!-- Pickup Time (shown when Pickup is selected) -->
            <div class="modal-pickup-time" id="modalPickupTime">
                <label for="modalPickupTimeSelect" class="modal-form-label">Pickup Time*</label>
                <div class="custom-dropdown" id="pickupTimeDropdown">
                    <div class="dropdown-input-wrapper">
                        <input type="text" id="modalPickupTimeSelect" class="dropdown-input"
                            placeholder="Select pickup time..." value="06:30 PM" readonly>
                        <svg class="dropdown-arrow" width="14" height="9" viewBox="0 0 14 9" fill="none"
                            xmlns="http://www.w3.org/2000/svg">
                            <path d="M1 1.5L7 7.5L13 1.5" stroke="#333" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" />
                        </svg>
                    </div>
                    <div class="dropdown-list" id="pickupTimeList">
                        <div class="dropdown-options" id="pickupTimeOptionsList">
                            <!-- Options will be populated by JavaScript -->
                        </div>
                    </div>
                </div>
                <input type="hidden" id="modalPickupTimeValue" value="06:30 PM">
            </div>

            <!-- Quantity Controls -->
            <div class="modal-quantity-section">
                <label class="quantity-label">Quantity</label>
                <div class="quantity-controls">
                    <button class="qty-btn decrease" id="qtyDecrease">-ˆ’</button>
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
                <span id="orderBtnText">Order for Pickup</span>
            </button>
        </div>
    </div>

    <script>
        // ============================================================================
        // LIVE TEXT SPOTLIGHT EFFECT
        // ============================================================================
        // Creates interactive spotlight effect on text when mouse moves over it
        // ============================================================================
        (function () {
            const liveTexts = document.querySelectorAll('.live-text');
            liveTexts.forEach(liveText => {
                liveText.addEventListener('mousemove', function (e) {
                    const rect = liveText.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;

                    liveText.style.setProperty('--x', x + 'px');
                    liveText.style.setProperty('--y', y + 'px');
                });

                // Reset to center when mouse leaves
                liveText.addEventListener('mouseleave', function () {
                    liveText.style.setProperty('--x', '50%');
                    liveText.style.setProperty('--y', '50%');
                });
            });
        })();



        // ============================================================================
        // SCROLL-TRIGGERED ANIMATIONS
        // ============================================================================
        // Animates top dishes section when it comes into view
        // Uses Intersection Observer API for performance
        // ============================================================================
        (function () {
            const dishesSection = document.getElementById('topDishesSection');
            if (!dishesSection) return;

            const observerOptions = {
                threshold: 0.2,
                rootMargin: '0px 0px -100px 0px'
            };

            const observer = new IntersectionObserver(function (entries) {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const section = entry.target;

                        // Animate header
                        const header = section.querySelector('.top-dishes__header--fade');
                        if (header) {
                            header.classList.add('animate');
                        }

                        // Animate dish cards with staggered delay
                        const cards = section.querySelectorAll('.dish-card');
                        cards.forEach((card, index) => {
                            setTimeout(() => {
                                card.classList.add('animate');
                            }, index * 150);
                        });

                        // Animate footer
                        const footer = section.querySelector('.top-dishes__footer--fade');
                        if (footer) {
                            setTimeout(() => {
                                footer.classList.add('animate');
                            }, 600);
                        }

                        // Unobserve after animation
                        observer.unobserve(section);
                    }
                });
            }, observerOptions);

            observer.observe(dishesSection);
        })();



        // ============================================================================
        // LANGUAGE SWITCHER
        // ============================================================================
        // Handles language selection and persistence (stored in localStorage)
        // ============================================================================
        (function () {
            const langButtons = document.querySelectorAll('.lang-btn');
            let currentLang = localStorage.getItem('selectedLanguage') || 'en';

            // Initialize language on page load
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

            // Switch language
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

                // Here you can add actual language switching logic
                // For now, we'll just update the UI
                console.log('Language switched to:', lang);
            }

            // Add click event listeners
            langButtons.forEach(btn => {
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    const lang = this.getAttribute('data-lang');
                    switchLanguage(lang);
                });
            });

            // Initialize on page load
            initLanguage();
        })();

    </script>


    <!-- ============================================================================
         GALLERY SLIDESHOW
         ============================================================================
         Handles automatic image slideshow with manual navigation controls
         Includes keyboard navigation and auto-play functionality
         ============================================================================ -->
    <!-- ============================================================================
         GALLERY SLIDESHOW
         ============================================================================
         Handles automatic image slideshow with manual navigation controls
         Includes keyboard navigation and auto-play functionality
         ============================================================================ -->
    <script>
        (function () {
            const slides = document.querySelectorAll('.dv-slide');
            const dotsContainer = document.getElementById('galleryDots');
            const prevBtn = document.querySelector('.dv-control.prev');
            const nextBtn = document.querySelector('.dv-control.next');
            const intervalTime = 4000; // 4 seconds per slide
            let currentSlide = 0;
            let slideInterval;
            let dots = [];

            if (!slides.length) return;

            // Initialize Gallery
            function initGallery() {
                // Generate dots
                if (dotsContainer) {
                    dotsContainer.innerHTML = '';
                    slides.forEach((_, index) => {
                        const dot = document.createElement('span');
                        dot.classList.add('dv-dot');
                        if (index === 0) dot.classList.add('active');
                        dot.addEventListener('click', () => goToSlide(index));
                        dotsContainer.appendChild(dot);
                        dots.push(dot);
                    });
                }

                // Show first slide
                showSlide(currentSlide);
                startAutoSlide();
            }

            // Show specific slide
            function showSlide(n) {
                // Wrap around
                if (n >= slides.length) currentSlide = 0;
                else if (n < 0) currentSlide = slides.length - 1;
                else currentSlide = n;

                // Update slides visual state
                slides.forEach((slide, index) => {
                    slide.classList.toggle('active', index === currentSlide);
                });

                // Update dots visual state
                dots.forEach((dot, index) => {
                    dot.classList.toggle('active', index === currentSlide);
                });
            }

            // Navigation Helpers
            window.moveSlide = function (n) {
                goToSlide(currentSlide + n);
            };

            function goToSlide(n) {
                showSlide(n);
                resetAutoSlide();
            }

            function startAutoSlide() {
                slideInterval = setInterval(() => {
                    showSlide(currentSlide + 1);
                }, intervalTime);
            }

            function stopAutoSlide() {
                clearInterval(slideInterval);
            }

            function resetAutoSlide() {
                stopAutoSlide();
                startAutoSlide();
            }

            // Event Listeners for Hover Pause
            const galleryContainer = document.querySelector('.dv-slideshow-container');
            if (galleryContainer) {
                galleryContainer.addEventListener('mouseenter', stopAutoSlide);
                galleryContainer.addEventListener('mouseleave', startAutoSlide);
            }

            // Keyboard Navigation
            document.addEventListener('keydown', (e) => {
                if (!galleryContainer || !document.contains(galleryContainer)) return;

                // Only if gallery is in viewport (optional check, omitted for simplicity)
                if (e.key === 'ArrowLeft') {
                    window.moveSlide(-1);
                } else if (e.key === 'ArrowRight') {
                    window.moveSlide(1);
                }
            });

            // Initialize
            initGallery();
        })();
    </script>

    <!-- Footer Section -->
    <?php require_once __DIR__ . '/includes/footer.php'; ?>

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

            <!-- Delivery/Pickup Toggle -->
            <div class="modal-service-toggle">
                <button class="modal-service-btn" id="modalDeliveryBtn" data-service="delivery">Delivery</button>
                <button class="modal-service-btn active" id="modalPickupBtn" data-service="pickup">Pickup</button>
            </div>

            <!-- Delivery Location (shown when Delivery is selected) -->
            <div class="modal-delivery-location" id="modalDeliveryLocation" style="display: none;">
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
                <button type="button" class="use-current-location-btn" id="useCurrentLocationBtn">
                    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M9 9.75C9.41421 9.75 9.75 9.41421 9.75 9C9.75 8.58579 9.41421 8.25 9 8.25C8.58579 8.25 8.25 8.58579 8.25 9C8.25 9.41421 8.58579 9.75 9 9.75Z"
                            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        <path
                            d="M9 2.25V4.5M9 13.5V15.75M15.75 9H13.5M4.5 9H2.25M14.1975 3.8025L12.7275 5.2725M5.2725 12.7275L3.8025 14.1975M14.1975 14.1975L12.7275 12.7275M5.2725 5.2725L3.8025 3.8025"
                            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span>Use Current Location</span>
                </button>
            </div>

            <!-- Pickup Time (shown when Pickup is selected) -->
            <div class="modal-pickup-time" id="modalPickupTime">
                <label for="modalPickupTimeSelect" class="modal-form-label">Pickup Time*</label>
                <div class="custom-dropdown" id="pickupTimeDropdown">
                    <div class="dropdown-input-wrapper">
                        <input type="text" id="modalPickupTimeSelect" class="dropdown-input"
                            placeholder="Select pickup time..." value="06:30 PM" readonly>
                        <svg class="dropdown-arrow" width="14" height="9" viewBox="0 0 14 9" fill="none"
                            xmlns="http://www.w3.org/2000/svg">
                            <path d="M1 1.5L7 7.5L13 1.5" stroke="#333" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" />
                        </svg>
                    </div>
                    <div class="dropdown-list" id="pickupTimeList">
                        <div class="dropdown-options" id="pickupTimeOptionsList">
                            <!-- Options will be populated by JavaScript -->
                        </div>
                    </div>
                </div>
                <input type="hidden" id="modalPickupTimeValue" value="06:30 PM">
            </div>

            <!-- Quantity Controls -->
            <div class="modal-quantity-section">
                <label class="quantity-label">Quantity</label>
                <div class="quantity-controls">
                    <button class="qty-btn decrease" id="qtyDecrease">-ˆ’</button>
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
                <span id="orderBtnText">Order for Pickup</span>
            </button>
        </div>
    </div>

    <!-- Login/Signup Required Modal -->
    <div class="login-required-modal" id="loginRequiredModal">
        <div class="modal-backdrop"></div>
        <div class="login-modal-content">
            <button class="login-modal-close" id="loginModalClose">&times;</button>
            <div class="login-modal-icon">
                <svg width="64" height="64" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="32" cy="32" r="30" stroke="currentColor" stroke-width="2" stroke-dasharray="5 5"
                        opacity="0.3" />
                    <path d="M32 20V32L40 40" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round" />
                </svg>
            </div>
            <h2 class="login-modal-title">Login Required</h2>
            <p class="login-modal-message">Please login or sign up to place an order</p>
            <div class="login-modal-actions">
                <a href="<?php echo $basePath; ?>/auth/login.php?next=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>"
                    class="login-modal-btn login-modal-btn-primary">
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
                    <span>Login</span>
                </a>
                <a href="<?php echo $basePath; ?>/auth/signup.php?next=<?php echo urlencode($_SERVER['REQUEST_URI']); ?>"
                    class="login-modal-btn login-modal-btn-secondary">
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
                    <span>Sign Up</span>
                </a>
            </div>
            <p class="login-modal-footer">New to JustKleek? Create an account to get started!</p>
        </div>
    </div>

    <!-- Email Verification Required Modal -->
    <?php if ($isLoggedIn && !$isVerified): ?>
        <?php require_once __DIR__ . '/includes/verification_modal.php'; ?>
    <?php endif; ?>

    <!-- Scripts -->
    <script>
        // Pass login status and verification status to JavaScript
        window.userLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
        window.userVerified = <?php echo $isVerified ? 'true' : 'false'; ?>;
        window.basePath = '<?php echo $basePath; ?>';
    </script>
    <script src="js/menu.js"></script>
    <script>
        // Chroma key solution for removing black background - Walking Character Animation
        (function () {
            const video = document.getElementById('walkerVideo');
            const canvas = document.getElementById('walkerCanvas');
            if (!video || !canvas) return;

            const ctx = canvas.getContext('2d');
            let isProcessing = false;
            let displayWidth = 120;
            let displayHeight = 170;

            // Set canvas size
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
                    const scale = Math.min(dpr, 2.5);

                    // Set canvas internal resolution
                    canvas.width = displayWidth * scale;
                    canvas.height = displayHeight * scale;

                    // Set CSS display size
                    canvas.style.width = displayWidth + 'px';
                    canvas.style.height = displayHeight + 'px';

                    // Scale context for high DPI
                    ctx.setTransform(scale, 0, 0, scale, 0, 0);

                    // Enable high-quality image smoothing
                    ctx.imageSmoothingEnabled = true;
                    ctx.imageSmoothingQuality = 'high';
                }
            }

            // Chroma key function
            function chromaKey() {
                // Get full image data using the physical canvas dimensions
                const width = canvas.width;
                const height = canvas.height;

                // Draw current video frame
                ctx.drawImage(video, 0, 0, displayWidth, displayHeight);

                // Get pixel data
                const frame = ctx.getImageData(0, 0, width, height);
                const l = frame.data.length / 4;

                for (let i = 0; i < l; i++) {
                    const r = frame.data[i * 4 + 0];
                    const g = frame.data[i * 4 + 1];
                    const b = frame.data[i * 4 + 2];

                    // Simple green screen detection
                    // If green is dominant and significantly brighter than red and blue
                    if (g > 100 && g > r * 1.4 && g > b * 1.4) {
                        frame.data[i * 4 + 3] = 0; // Set alpha to 0 (transparent)
                    }
                }

                ctx.putImageData(frame, 0, 0);

                if (isProcessing) {
                    requestAnimationFrame(chromaKey);
                }
            }

            video.addEventListener('play', () => {
                setCanvasSize();
                isProcessing = true;
                chromaKey();
            });

            video.addEventListener('pause', () => {
                isProcessing = false;
            });

            video.addEventListener('ended', () => {
                isProcessing = false;
            });

            // Handle window resize
            window.addEventListener('resize', setCanvasSize);

            // Initial setup if video is already ready
            if (video.readyState >= 1) {
                setCanvasSize();
            } else {
                video.addEventListener('loadedmetadata', setCanvasSize);
            }
        })();
    </script>

    <!-- Hero Timer Logic Consolidated into index.php -->
    <script src="<?php echo $basePath; ?>/assets/js/dv_mobile_nav.js" defer></script>

    <script>
        // Cinematic Text Observer
        document.addEventListener('DOMContentLoaded', () => {
            const observerOptions = {
                root: null,
                rootMargin: '0px',
                threshold: 0.2
            };

            const observer = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('in-view');
                        observer.unobserve(entry.target); // Run once
                    }
                });
            }, observerOptions);

            document.querySelectorAll('.cinematic-text, .cinematic-slide-in-right, .cinematic-slide-in-left, .cinematic-fade-up').forEach(el => {
                observer.observe(el);
            });
        });
    </script>
    <script>
        // Cinematic Slideshow Logic
        document.addEventListener('DOMContentLoaded', () => {
            const track = document.getElementById('galleryTrack');
            if (!track) return;

            const slides = track.querySelectorAll('.dv-slide');
            const dotsContainer = document.getElementById('galleryDots');
            const slideCount = slides.length;
            let currentSlide = 0;
            let interval;

            // Create dots
            slides.forEach((_, index) => {
                const dot = document.createElement('div');
                dot.classList.add('dv-dot');
                if (index === 0) dot.classList.add('active');
                dot.addEventListener('click', () => goToSlide(index));
                dotsContainer.appendChild(dot);
            });

            const dots = dotsContainer.querySelectorAll('.dv-dot');

            function updateSlides() {
                // Update slides
                slides.forEach((slide, index) => {
                    if (index === currentSlide) {
                        slide.classList.add('active');
                    } else {
                        slide.classList.remove('active');
                    }
                });

                // Update dots
                dots.forEach((dot, index) => {
                    if (index === currentSlide) {
                        dot.classList.add('active');
                    } else {
                        dot.classList.remove('active');
                    }
                });
            }

            function nextSlide() {
                currentSlide = (currentSlide + 1) % slideCount;
                updateSlides();
            }

            function prevSlide() {
                currentSlide = (currentSlide - 1 + slideCount) % slideCount;
                updateSlides();
            }

            // Expose to global scope for button onclicks
            window.moveSlide = function (direction) {
                clearInterval(interval);
                if (direction === 1) nextSlide();
                else prevSlide();
                startAutoPlay();
            };

            // Direct jump
            function goToSlide(index) {
                clearInterval(interval);
                currentSlide = index;
                updateSlides();
                startAutoPlay();
            }

            function startAutoPlay() {
                interval = setInterval(nextSlide, 5000); // 5 seconds per slide
            }

            startAutoPlay();
        });
    </script>
    <script>
        // Live Stock Updating for Frontend
        (function () {
            function updateLiveStock() {
                fetch(window.location.origin + (window.basePath || '') + '/api/get_stock.php?branch_id=<?php echo $selectedBranchId; ?>')
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            data.stocks.forEach(item => {
                                const name = item.item_name.toLowerCase();
                                const isOut = item.stock_count <= 0;

                                // Find all menu cards for this item (works for both Home and Menu)
                                const cards = document.querySelectorAll(`.menu-card[data-item-name="${name}"]`);
                                cards.forEach(card => {
                                    const currentlyOut = card.classList.contains('out-of-stock');

                                    if (isOut !== currentlyOut) {
                                        // Toggle class
                                        if (isOut) {
                                            card.classList.add('out-of-stock');
                                        } else {
                                            card.classList.remove('out-of-stock');
                                        }

                                        // Update button state
                                        const btn = card.querySelector('.menu-item-btn');
                                        const cartBtn = card.querySelector('.menu-cart-btn');
                                        if (btn) {
                                            btn.disabled = isOut;
                                            const span = btn.querySelector('span');
                                            if (span) span.textContent = isOut ? 'Out of Stock' : 'Order Now';

                                            // Handle SVG arrow
                                            if (isOut) {
                                                const svg = btn.querySelector('svg');
                                                if (svg) svg.remove();
                                            } else if (!btn.querySelector('svg')) {
                                                const svg = `
                                                    <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                                        <path d="M6.75 13.5L11.25 9L6.75 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                                    </svg>`;
                                                btn.insertAdjacentHTML('beforeend', svg);
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
                    .catch(err => console.error('Stock poll failed', err));
            }

            // Poll every 5 seconds for live updates
            setInterval(updateLiveStock, 5000);
        })();
    </script>
    <!-- Branch Selection Modal (Auto-included via centralized component) -->
    <?php include __DIR__ . '/includes/branch_selector.php'; ?>

    <script>
        // Intercept Menu Clicks on Homepage
        function handleMenuClick(event) {
            if (!isBranchChosen) {
                event.preventDefault();
                openBranchModal();
                return false;
            }
            return true;
        }

        // Attach to all Menu/Order Now links on homepage
        document.addEventListener('DOMContentLoaded', () => {
            const menuLinks = document.querySelectorAll('a[href="menu"], .hero-cta--menu, .momos-section__button');
            menuLinks.forEach(link => {
                link.onclick = handleMenuClick;
            });
        });
    </script>
</body>

</html>