<?php
/**
 * Navbar Include
 * Shows login/logout and conditional menu items based on user authentication
 */

require_once __DIR__ . '/../app/functions/security.php';
require_once __DIR__ . '/../app/functions/auth.php';

initSecureSession();
$isLoggedIn = isUserLoggedIn();
$user = $isLoggedIn ? getCurrentUser() : null;
// If getCurrentUser returns false (user not found in DB), treat as not logged in
if ($user === false) {
    if ($isLoggedIn) {
        error_log("DEBUG_LOGIN (navbar.php): User was theoretically logged in but getCurrentUser returned false. Clearing session.");
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
error_log("DEBUG_LOGIN (navbar.php): Render. URL: " . $_SERVER['REQUEST_URI'] . ". isLoggedIn=" . ($isLoggedIn ? 'true' : 'false') . ". Session user_id=" . ($_SESSION['user_id'] ?? 'MISSING'));

// --- BRANCH INFO ---
$branchName = "";
if (isset($_SESSION['customer_branch_id'])) {
    try {
        require_once __DIR__ . '/../config/db.php';
        global $pdo;
        if (isset($pdo)) {
            $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
            $stmt->execute([$_SESSION['customer_branch_id']]);
            $branchName = $stmt->fetchColumn();
        }
    } catch (Exception $e) {}
}
// -------------------
?>

<style>
    /* =========================================
       RESET & CORE UTILITIES
       ========================================= */
    .navbar * {
        box-sizing: border-box;
    }

    /* Core Navbar Structure */
    .navbar {
        position:
            <?php echo (basename($_SERVER['PHP_SELF']) == 'index.php' && strpos($_SERVER['PHP_SELF'], 'admin') === false) ? 'absolute' : 'relative'; ?>
        ;
        width: 100%;
        top: 0;
        left: 0;
        z-index: 9999999;
        background: linear-gradient(135deg,
            rgba(34, 139, 80, 0.95) 0%,
            rgba(40, 160, 90, 0.92) 50%,
            rgba(34, 139, 80, 0.95) 100%) !important;
        box-shadow: 0 4px 20px rgba(20, 100, 50, 0.25) !important;
        border-bottom: 1px solid rgba(60, 180, 100, 0.45) !important;
        border-top: none !important;
        border-left: none !important;
        border-right: none !important;
        /* Ensure specific header styling */
        padding: 24px 0 12px 0 !important;
        /* Restore original padding */
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
    }

    .navbar__container {
        display: flex;
        align-items: center;
        /* Allow items to center vertically properly */
    }

    /* Mobile Buttons Container (Hamburger, Login) */
    .navbar__mobile-buttons {
        z-index: 2147483647 !important;
        /* Highest Priority */
        position: relative;
        display: flex;
        align-items: center;
        gap: 15px;
    }

    /* =========================================
       ========================================= */
    @media (max-width: 768px) {
        .navbar__menu {
            display: none !important;
        }
    }




    /* =========================================
       DESKTOP ENHANCEMENTS (min-width: 769px)
       ========================================= */
    @media (min-width: 769px) {
        .navbar__menu {
            display: flex;
            gap: 20px;
            position: static;
            height: auto;
            width: auto;
            background: transparent;
            transform: none;
            visibility: visible;
            padding: 0;
            box-shadow: none;
        }

        /* Hide Mobile Elements on Desktop */
        .navbar__drawer-close,
        .navbar__overlay {
            display: none !important;
        }

        /* Desktop Link Styling */
        .navbar__link {
            position: relative;
            padding: 8px 12px;
            font-weight: 500;
            color: #333;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Hover Line Effect */
        .navbar__link::after {
            content: '';
            position: absolute;
            bottom: 0px;
            left: 50%;
            width: 0;
            height: 2px;
            background: var(--orange, #FF8C00);
            transition: all 0.3s ease;
            transform: translateX(-50%);
            border-radius: 2px;
        }

        .navbar__link:hover::after,
        .navbar__link.active::after {
            width: 80%;
            animation: none !important;
        }

        /* Logo Shimmer */
        .logo__text {
            background: linear-gradient(to right, #d97706 0%, #fbbf24 20%, #d97706 40%, #d97706 100%);
            background-size: 200% auto;
            color: transparent;
            -webkit-background-clip: text;
            background-clip: text;
            animation: shine 4s ease-in-out infinite;
        }

        @keyframes shine {
            0% {
                background-position: 200% center;
            }

            100% {
                background-position: 0% center;
            }
        }
    }



    @media (max-width: 768px) {
        .logo-link img {
            height: 220px !important;
            /* Restore original height */
        }

        /* Mobile drawer active link styling */
        .dv-nav-links a.nav-active:not(.dv-nav-logout) {
            border: 1.5px solid var(--orange, #FF8C00);
            background: rgba(255, 140, 0, 0.06);
            color: #111827;
        }
    }
</style>
<nav class="navbar">
    <div class="navbar__container" style="position: relative; z-index: 1000;">
        <div class="navbar__logo">
            <a href="<?php echo $basePath; ?>/index"
                class="logo-link" style="display: block; height: 50px; width: 130px; position: relative;">
                <img src="<?php echo $basePath; ?>/assets/logo.png" alt="JustKleek"
                    style="height: 140px; width: auto; object-fit: contain; position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%);">
            </a>
        </div>



        <!-- Mobile Header Buttons (V3 Implementation) -->
        <div class="dv-mnav-right">
            <?php
            $profileUrl = $isLoggedIn ? $basePath . '/profile' : $basePath . '/auth/login.php';

            // Detect Avatar (ROBUST DB CHECK - matching desktop logic)
            $avatarUrl = null;
            if ($isLoggedIn && !empty($user) && is_array($user) && !empty($user['id'])) {
                // Prefer already-loaded user data (avoid extra DB queries on every request).
                if (!empty($user['profile_picture'])) {
                    $avatarUrl = (string) $user['profile_picture'];
                } elseif (!empty($_SESSION['user']['profile_image'])) {
                    $avatarUrl = (string) $_SESSION['user']['profile_image'];
                } elseif (!empty($_SESSION['user']['avatar'])) {
                    $avatarUrl = (string) $_SESSION['user']['avatar'];
                }

                // Sanitize path: allow absolute URLs and site-root paths; otherwise prefix basePath.
                if (!empty($avatarUrl) && !preg_match('/^(https?:\/\/|\/)/', $avatarUrl)) {
                    $avatarUrl = $basePath . '/' . ltrim($avatarUrl, '/');
                }
            }
            ?>

            <?php if ($isLoggedIn): ?>
                <?php
                // Determine which image to show for logged-in user
                // Use $avatarUrl if detected, otherwise fallback to userlogo.png
                $displayImg = !empty($avatarUrl) ? $avatarUrl : $basePath . '/assets/userlogo.png';
                ?>
                <a class="dv-mnav-profile" href="<?= $basePath ?>/profile" aria-label="Profile">
                    <img class="dv-mnav-avatar" src="<?= htmlspecialchars($displayImg) ?>" alt="Profile"
                        onerror="this.src='<?= $basePath ?>/assets/userlogo.png'">
                </a>
            <?php else: ?>
                <a class="dv-mnav-profile" href="<?= $basePath ?>/auth/login.php" aria-label="Login">
                    <img class="dv-mnav-avatar" src="<?= $basePath ?>/assets/loginlogo.jpg" alt="Login">
                </a>
            <?php endif; ?>

            <button id="dvMnavBtn" class="dv-mnav-btn" aria-label="Open menu" aria-controls="dvMnavDrawer"
                aria-expanded="false">
                <span class="dv-mnav-bars"></span>
            </button>
        </div>

        <ul class="navbar__menu" id="navbarMenu">
            <!-- Close Button Removed (Handled by mobile_nav.php) -->

            <li><a href="<?php echo $basePath; ?>/index"
                    class="navbar__link <?php echo basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : ''; ?>">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M3.33334 10L10 2.5L16.6667 10" stroke="currentColor" stroke-width="1.5"
                            stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M15 17.5H12.5V12.5H7.5V17.5H5V10H3.33334L10 3.33334L16.6667 10H15V17.5Z"
                            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span>Home</span>
                </a></li>
            <li><a href="<?php echo $basePath; ?>/menu"
                    class="navbar__link <?php echo basename($_SERVER['PHP_SELF']) === 'menu.php' ? 'active' : ''; ?>">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M3.33334 3.33334H16.6667C17.5871 3.33334 18.3333 4.07953 18.3333 5.00001V15C18.3333 15.9205 17.5871 16.6667 16.6667 16.6667H3.33334C2.41286 16.6667 1.66667 15.9205 1.66667 15V5.00001C1.66667 4.07953 2.41286 3.33334 3.33334 3.33334Z"
                            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M6.66667 7.5H13.3333" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                            stroke-linejoin="round" />
                        <path d="M6.66667 10.8333H13.3333" stroke="currentColor" stroke-width="1.5"
                            stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M6.66667 14.1667H10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                            stroke-linejoin="round" />
                    </svg>
                    <span>Menu</span>
                </a></li>
            <?php if (!$isLoggedIn): ?>
                <li class="navbar__item--desktop-login"><a href="<?php echo $basePath; ?>/auth/login.php"
                        class="navbar__link <?php echo basename($_SERVER['PHP_SELF']) === 'login.php' ? 'active' : ''; ?>">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M10 10C12.7614 10 15 7.76142 15 5C15 2.23858 12.7614 0 10 0C7.23858 0 5 2.23858 5 5C5 7.76142 7.23858 10 10 10Z"
                                stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            <path
                                d="M10 12.5C6.66667 12.5 3.33334 14.1667 2.5 17.5H17.5C16.6667 14.1667 13.3333 12.5 10 12.5Z"
                                stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span>Login</span>
                    </a></li>
            <?php endif; ?>
            <?php if ($isLoggedIn): ?>

                <li><a href="<?php echo $basePath; ?>/cart"
                        class="navbar__link navbar__cart-link <?php echo basename($_SERVER['PHP_SELF']) === 'cart.php' ? 'active' : ''; ?>">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M5 7H15L14 13H6L5 7Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                stroke-linejoin="round" />
                            <path
                                d="M7.5 16.5C8.05228 16.5 8.5 16.0523 8.5 15.5C8.5 14.9477 8.05228 14.5 7.5 14.5C6.94772 14.5 6.5 14.9477 6.5 15.5C6.5 16.0523 6.94772 16.5 7.5 16.5Z"
                                stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            <path
                                d="M13.5 16.5C14.0523 16.5 14.5 16.0523 14.5 15.5C14.5 14.9477 14.0523 14.5 13.5 14.5C12.9477 14.5 12.5 14.9477 12.5 15.5C12.5 16.0523 12.9477 16.5 13.5 16.5Z"
                                stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M5 7L4 3H2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                stroke-linejoin="round" />
                        </svg>
                        <span>Cart</span>
                    </a></li>
                <li><a href="<?php echo $basePath; ?>/track-order"
                        class="navbar__link <?php echo basename($_SERVER['PHP_SELF']) === 'order-tracking.php' ? 'active' : ''; ?>">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M10 18.3333C14.6024 18.3333 18.3333 14.6024 18.3333 10C18.3333 5.39763 14.6024 1.66667 10 1.66667C5.39763 1.66667 1.66667 5.39763 1.66667 10C1.66667 14.6024 5.39763 18.3333 10 18.3333Z"
                                stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M10 6.66667V10L12.5 12.5" stroke="currentColor" stroke-width="1.5"
                                stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span>Track Order</span>
                    </a></li>
            <?php endif; ?>
            <?php if ($isLoggedIn): ?>
                <!-- Profile Link (Desktop Only) -->
                <li class="navbar__item--desktop-profile">
                    <?php
                    // Get profile picture for desktop
                    $desktopProfilePic = null;
                    if (!empty($user) && is_array($user) && !empty($user['id'])) {
                        if (!empty($user['profile_picture'])) {
                            $desktopProfilePic = (string) $user['profile_picture'];
                            if (!preg_match('/^(https?:\/\/|\/)/', $desktopProfilePic)) {
                                $desktopProfilePic = $basePath . '/' . ltrim($desktopProfilePic, '/');
                            }
                        }
                    }
                    // Use userlogo.png as fallback if no profile picture
                    if (!$desktopProfilePic) {
                        $desktopProfilePic = $basePath . '/assets/userlogo.png';
                    }
                    ?>
                    <a href="<?php echo $basePath; ?>/profile" class="navbar__link navbar__link--profile-desktop">
                        <img src="<?php echo e($desktopProfilePic); ?>" alt="Profile" class="navbar__profile-avatar-desktop"
                            onerror="this.src='<?php echo $basePath; ?>/assets/userlogo.png';">
                        <span>Profile</span>
                    </a>
                </li>
                <!-- Logout Link (Mobile Only) -->
                <li class="navbar__item--mobile-logout"><a href="<?php echo $basePath; ?>/auth/logout.php"
                        class="navbar__link navbar__link--logout">
                        <svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M7.5 17.5H4.16667C3.24619 17.5 2.5 16.7538 2.5 15.8333V4.16667C2.5 3.24619 3.24619 2.5 4.16667 2.5H7.5"
                                stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M13.3333 14.1667L17.5 10L13.3333 5.83333" stroke="currentColor" stroke-width="1.5"
                                stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M17.5 10H7.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                stroke-linejoin="round" />
                        </svg>
                        <span>Logout</span>
                    </a></li>
            <?php endif; ?>
        </ul>
    </div>

    <!-- Real Overlay Element -->
    <div class="navbar__overlay" id="navbarOverlay"></div>

    <!-- Storytelling Entertainment Section -->
    <div class="navbar__entertainment" id="navbarStoryStage">
        <!-- Actors -->
        <div class="story-actor actor-chef" id="actorChef">👨‍🍳</div>
        <div class="story-actor actor-pot" id="actorPot">🥘</div>
        <div class="story-actor actor-fire" id="actorFire">🔥</div>
        <div class="story-actor actor-waiter" id="actorWaiter">💁‍♂️</div>
        <div class="story-actor actor-plate" id="actorPlate">🍛</div>
        <div class="story-actor actor-scooter" id="actorScooter">🛵</div>

        <!-- Story Text Bubble -->
        <div class="story-bubble" id="storyBubble">Let's Cook!</div>
    </div>
</nav>

<!-- Mobile Navigation V3 Drawer (Outside Navbar Container) -->
<div id="dvMnavOverlay" class="dv-mnav-overlay" hidden></div>

<aside id="dvMnavDrawer" class="dv-mnav-drawer" aria-hidden="true">
    <div class="dv-mnav-top">
        <div class="dv-mnav-title">JustKleek</div>
        <button id="dvMnavClose" class="dv-mnav-close" aria-label="Close menu">✕</button>
    </div>

    <nav class="dv-mnav-links">
        <a href="<?= $basePath ?>/index">Home</a>
        <a href="<?= $basePath ?>/menu">Menu</a>
        <?php if (isset($_SESSION['user_id'])): ?>

            <a href="<?= $basePath ?>/cart" id="dvMnavCartLink">Cart</a>
            <a href="<?= $basePath ?>/track-order">Track Order</a>
            <a href="<?= $basePath ?>/profile">Profile</a>

            <form class="dv-mnav-logout" method="get" action="<?= $basePath ?>/auth/logout.php" style="margin:0;">
                <button type="submit">Logout</button>
            </form>
            <div style="margin-top: 10px; text-align: center; font-size: 11px; color: #9ca3af;">
                Nav 3.0 · Dev By Prabin
            </div>
        <?php endif; ?>
    </nav>
</aside>

<!-- V3 Mobile Nav Assets -->

<script defer src="<?= $basePath ?>/assets/js/dv_mobile_nav_v3.js?v=1.0.1"></script>

<!-- Logout Confirmation Logic -->
<?php include __DIR__ . '/logout_modal.php'; ?>


<script>     /**      * DA VATTI VISUAL STORYTELLING      * Concept: "The Journey of Food"      * A clear, meaningful 3-step sequence:       * 1. Preparation (Chef + Pot)      * 2. Service (Waiter + Plate)      * 3. Delivery (Scooter) - NOW with moving text!      */
    document.addEventListener('DOMContentLoaded', () => {
        try {
            const stage = document.getElementById('navbarStoryStage');
            const bubble = document.getElementById('storyBubble');

            // Actors
            const actors = {
                chef: document.getElementById('actorChef'),
                pot: document.getElementById('actorPot'),
                fire: document.getElementById('actorFire'),
                waiter: document.getElementById('actorWaiter'),
                plate: document.getElementById('actorPlate'),
                scooter: document.getElementById('actorScooter')
            };

            if (!stage) return;

            // Reset all actors to starting positions
            const resetStage = () => {
                Object.values(actors).forEach(actor => {
                    if (actor) {
                        actor.style.transition = 'none';
                        actor.style.opacity = '0';
                        actor.style.transform = 'translate(-35px, 0)';
                    }
                });
                if (bubble) {
                    bubble.style.transition = 'none';
                    bubble.style.opacity = '0';
                    bubble.style.transform = 'translate(0, 0) translateX(-50%) scale(0.8)';
                }
            };

            const wait = (ms) => new Promise(r => setTimeout(r, ms));

            // Helper to show text (Centered over xPos)
            const showText = (text, xPos, duration = 3000) => {
                if (!bubble) return;
                bubble.innerText = text;

                // xPos should be the center point of the actor
                let targetX = xPos;
                if (xPos === 'center') targetX = window.innerWidth / 2;

                bubble.style.left = targetX + 'px';
                bubble.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                bubble.style.opacity = '1';
                // Use translateX(-50%) to perfectly center the bubble pointer on the targetX
                bubble.style.transform = 'translateX(-50%) scale(1)';

                setTimeout(() => {
                    if (bubble.innerText === text) {
                        bubble.style.opacity = '0';
                        bubble.style.transform = 'translateX(-50%) scale(0.8)';
                    }
                }, duration);
            };

            // --- THE STORIES ---

            // Story 1: "We Cook with Love"
            const storyCooking = async () => {
                resetStage();
                let center = window.innerWidth / 2;

                if (window.innerWidth > 768) {
                    const homeLink = document.querySelector('nav .navbar__menu li:nth-child(1) a');
                    const menuLink = document.querySelector('nav .navbar__menu li:nth-child(2) a');
                    if (homeLink && menuLink) {
                        const rH = homeLink.getBoundingClientRect();
                        const rM = menuLink.getBoundingClientRect();
                        // Middle between end of Home and start of Menu + slight right shift
                        center = ((rH.right + rM.left) / 2) + 15;
                    }
                }
                const chefPos = center - 40;

                // 1. Fire appears
                if (actors.fire) {
                    actors.fire.style.opacity = '1';
                    actors.fire.style.left = (center + 20) + 'px';
                    actors.fire.style.transform = 'scale(0)';
                    actors.fire.style.transition = 'transform 0.5s ease-out';
                    setTimeout(() => actors.fire.style.transform = 'scale(1)', 100);
                }

                // 2. Chef walks in with Pot
                if (actors.chef && actors.pot) {
                    actors.chef.style.opacity = '1';
                    actors.pot.style.opacity = '1';

                    // Slower entry (4s)
                    actors.chef.style.transition = 'transform 4s linear';
                    actors.pot.style.transition = 'transform 4s linear';

                    actors.chef.style.transform = `translate(${chefPos}px, 0)`;
                    actors.pot.style.transform = `translate(${center}px, 0)`;
                }

                await wait(4000); // 4s wait for entry

                // 3. Cooking Action - Point bubble at Chef (chefPos + half width)
                showText("Freshly Cooked! 🔥", chefPos + 15);

                // Wiggle the pot
                if (actors.pot) {
                    actors.pot.classList.add('wiggle');
                    await wait(2000);
                    actors.pot.classList.remove('wiggle');
                }

                // 4. Chef leaves
                if (actors.chef && actors.pot) {
                    actors.chef.style.transition = 'transform 4s linear';
                    actors.pot.style.transition = 'transform 4s linear';
                    if (actors.fire) actors.fire.style.opacity = '0'; // Fire goes out

                    actors.chef.style.transform = `translate(${window.innerWidth + 50}px, 0)`;
                    actors.pot.style.transform = `translate(${window.innerWidth + 90}px, 0)`;
                }

                await wait(4000); // 4s wait for exit
            };

            // Story 2: "Served Hot"
            const storyServing = async () => {
                resetStage();

                // Calculate dynamic position between Menu and Reservation
                let center = window.innerWidth / 2;

                // Only attempt smart positioning on Desktop (where links are visible)
                if (window.innerWidth > 768) {
                    const menuLink = document.querySelector('a[href*="menu.php"]');

                    if (menuLink) {
                        const r1 = menuLink.getBoundingClientRect();
                        // Position slightly to the right of menu
                        center = r1.right + 20;
                    }
                }

                const waiterPos = center - 20;

                // Waiter enters carrying plate
                if (actors.waiter && actors.plate) {
                    actors.waiter.style.opacity = '1';
                    actors.plate.style.opacity = '1';

                    // Slower entry (5s)
                    actors.waiter.style.transition = 'transform 5s ease-in-out';
                    actors.plate.style.transition = 'transform 5s ease-in-out';

                    // Walk to center (dynamic)
                    actors.waiter.style.transform = `translate(${waiterPos}px, 0)`;
                    actors.plate.style.transform = `translate(${center + 10}px, -5px)`;
                }

                await wait(5000); // 5s wait for entry

                // Point bubble at Waiter (waiterPos + half width)
                showText("Your Food is Ready 🍽️", waiterPos + 15);

                await wait(2500);

                // Continue walking off
                if (actors.waiter && actors.plate) {
                    actors.waiter.style.transform = `translate(${window.innerWidth + 50}px, 0)`;
                    actors.plate.style.transform = `translate(${window.innerWidth + 80}px, -5px)`;
                }

                await wait(5000); // 5s wait for exit
            };

            // Story 3: "Fast Delivery" (Updated)
            const storyDelivery = async () => {
                resetStage();
                const w = window.innerWidth;
                const duration = 8; // Slower cruise (8s)

                // 1. Setup Scooter Facing Right (mirror) & Off-screen Left
                if (actors.scooter) {
                    actors.scooter.style.transition = 'none';
                    actors.scooter.style.opacity = '1';
                    actors.scooter.style.transform = `translate(-100px, 0) scaleX(-1)`;
                }

                // 2. Setup Bubble attached to Scooter
                if (bubble) {
                    bubble.innerHTML = 'We Deliver to You! <span style="display:inline-block; transform: scaleX(-1);">🛵</span>';
                    bubble.style.transition = 'none';
                    bubble.style.left = '0'; // align with transform origin
                    bubble.style.opacity = '1'; // Ensure visible
                    // Start position: -100px (scooter X) + 15px (center) = -85px
                    bubble.style.transform = `translate(-85px, 0) translateX(-50%) scale(1)`;
                }

                await wait(100);

                // 3. Move Both Together across the screen
                if (actors.scooter && bubble) {
                    actors.scooter.style.transition = `transform ${duration}s linear`;
                    bubble.style.transition = `transform ${duration}s linear`;

                    // Move way past the right edge
                    const targetX = w + 150;

                    actors.scooter.style.transform = `translate(${targetX}px, 0) scaleX(-1)`;
                    // Bubble target: targetX + 15px (center offset)
                    bubble.style.transform = `translate(${targetX + 15}px, 0) translateX(-50%) scale(1)`;
                }

                // Wait for travel to finish
                await wait(duration * 1000);
            };

            // Main Loop
            const runStories = async () => {
                while (true) {
                    try {
                        await storyCooking();
                        await wait(2000); // Consistent 2s gap

                        await storyServing();
                        await wait(2000); // Consistent 2s gap

                        await storyDelivery();
                        await wait(2000); // Consistent 2s gap
                    } catch (err) {
                        console.error("Animation loop error:", err);
                        await wait(5000); // Wait before retrying
                    }
                }
            };

            setTimeout(runStories, 100); // Start sooner
        } catch (e) {
            console.error("Critical Animation Init Error:", e);
        }
    });
</script>

<style>
    .navbar__entertainment {
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        height: 0;
        pointer-events: none;
        overflow: visible;
        z-index: 995;
    }

    .story-actor {
        position: absolute;
        bottom: 12px;
        left: 0;
        font-size: 26px;
        line-height: 1;
        width: 30px;
        height: 30px;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        will-change: transform;
        filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.1));
        pointer-events: none;
    }

    /* specific positioning tweaks */
    #actorFire {
        bottom: 12px;
        font-size: 20px;
        z-index: 996;
        display: none !important;
        /* Removed per user request */
    }

    #actorPot {
        bottom: 12px;
        z-index: 997;
        display: none !important;
        /* Removed per user request */
    }

    #actorPlate {
        font-size: 20px;
        z-index: 997;
    }

    #actorScooter {
        font-size: 32px;
        z-index: 998;
    }

    .story-bubble {
        position: absolute;
        bottom: 50px;
        background: rgba(255, 255, 255, 0.95);
        padding: 6px 14px;
        border-radius: 16px;
        border: 1px solid var(--orange);
        color: var(--black);
        font-size: 12px;
        font-weight: 600;
        white-space: nowrap;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08);
        z-index: 999;
        opacity: 0;
        transition: opacity 0.4s ease, transform 0.4s ease;
        transform-origin: bottom center;
        /* Ensure bubble doesn't wrap weirdly */
        width: max-content;
        left: 50%;
        /* Default, overridden by JS */
        transform: translateX(-50%) scale(0.8);
        /* Center alignment */
    }

    .story-bubble::after {
        content: '';
        position: absolute;
        bottom: -5px;
        left: 50%;
        margin-left: -5px;
        /* Center the arrow */
        border-width: 6px 6px 0;
        border-style: solid;
        border-color: var(--orange) transparent;
    }

    .wiggle {
        animation: wiggleAnimation 0.5s ease-in-out infinite;
    }

    @keyframes wiggleAnimation {

        0%,
        100% {
            transform: translateY(0) rotate(0);
        }

        25% {
            transform: translateY(-3px) rotate(-5deg);
        }

        75% {
            transform: translateY(1px) rotate(5deg);
        }
    }

    @media (max-width: 768px) {
        .story-actor {
            font-size: 22px;
            bottom: 10px;
            /* Slight lift */
            z-index: 2000000 !important;
            /* Force priority */
        }

        .story-bubble {
            bottom: 42px;
            font-size: 11px;
            padding: 4px 10px;
            z-index: 2000001 !important;
        }

        #actorScooter {
            font-size: 26px;
        }

        /* Ensure stage is top */
        .navbar__entertainment {
            z-index: 2000000 !important;
            height: 0;
            overflow: visible !important;
        }

        /* Ensure navbar doesn't clip */
        .navbar {
            overflow: visible !important;
        }
    }
</style>

<style>
    .navbar__auth {
        margin-left: auto;
    }

    @media (max-width: 768px) {
        .navbar__auth {
            display: none;
        }
    }

    /* Mobile Header Buttons Container */
    .navbar__mobile-buttons {
        display: none;
    }

    @media (max-width: 768px) {
        .navbar__mobile-buttons {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-left: auto;
            height: 48px;
            /* Match hamburger button height */
        }

        /* Mobile Login Button */
        .navbar__mobile-login-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            background: rgba(255, 255, 255, 0.95);
            border: 2px solid rgba(255, 165, 59, 0.3);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(255, 165, 59, 0.15);
            -webkit-tap-highlight-color: transparent;
            touch-action: manipulation;
            flex-shrink: 0;
        }

        .navbar__mobile-login-btn:hover {
            background: rgba(255, 165, 59, 0.1);
            border-color: var(--orange);
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(255, 165, 59, 0.3);
        }

        .navbar__mobile-login-btn:active {
            transform: scale(0.95);
        }

        .navbar__mobile-login-icon,
        .navbar__mobile-login-icon-fallback {
            width: 44px;
            height: 44px;
            object-fit: contain;
            display: block;
        }

        .navbar__mobile-login-icon-fallback {
            color: var(--orange);
        }

        /* Mobile Profile Button */
        .navbar__mobile-profile-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            background: rgba(255, 255, 255, 0.95);
            border: 2px solid rgba(102, 126, 234, 0.3);
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.15);
            -webkit-tap-highlight-color: transparent;
            touch-action: manipulation;
            flex-shrink: 0;
        }

        .navbar__mobile-profile-btn:hover {
            background: rgba(102, 126, 234, 0.1);
            border-color: #667eea;
            transform: scale(1.05);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.3);
        }

        .navbar__mobile-profile-btn:active {
            transform: scale(0.95);
        }

        .navbar__mobile-profile-img,
        .navbar__mobile-profile-initials {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }

        .navbar__mobile-profile-initials {
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: 700;
            font-size: 14px;
            font-family: 'Montserrat', sans-serif;
        }

        /* Ensure hamburger button aligns with other buttons */
        .navbar__mobile-buttons .navbar__hamburger {
            margin-top: 0;
            align-self: center;
            flex-shrink: 0;
        }
    }

    .navbar__link-icon {
        width: 20px;
        height: 20px;
        object-fit: contain;
        display: inline-block;
        vertical-align: middle;
    }

    /* Cart count badge (desktop + mobile) */
    .cart-count-badge {
        position: absolute;
        top: -8px;
        right: -8px;
        background: #ff4444;
        color: #ffffff;
        border-radius: 999px;
        min-width: 20px;
        height: 20px;
        padding: 0 6px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        font-weight: 800;
        box-shadow: 0 2px 8px rgba(255, 68, 68, 0.4);
        border: 2px solid #ffffff;
        line-height: 1;
        z-index: 10;
    }

    .navbar__cart-link {
        position: relative;
    }

    .navbar__link-icon--logo {
        filter: brightness(0) saturate(100%) invert(27%) sepia(51%) saturate(2878%) hue-rotate(224deg) brightness(102%) contrast(92%);
    }

    .navbar__link--profile {
        padding: 4px;
        background: transparent;
        border-radius: 50%;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 40px;
        height: 40px;
        border: 2px solid transparent;
    }

    .navbar__link--profile:hover {
        border-color: #667eea;
        background: rgba(102, 126, 234, 0.1);
        transform: scale(1.1);
    }

    .navbar__profile-avatar {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        object-fit: cover;
    }

    .navbar__profile-initials {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 14px;
    }

    .navbar__link--profile-menu {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .navbar__profile-avatar-small {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        overflow: hidden;
        border: 1px solid rgba(102, 126, 234, 0.2);
    }

    .navbar__profile-img-small {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 50%;
    }

    .navbar__profile-initials-small {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 10px;
    }

    @media (max-width: 768px) {
        .navbar__profile-avatar-small {
            width: 20px;
            height: 20px;
        }

        .navbar__profile-initials-small {
            font-size: 10px;
        }

        /* Logout Button Styling - Red Background */
        .navbar__link--logout {
            background: #dc3545 !important;
            color: #ffffff !important;
            border: 2px solid #dc3545 !important;
            border-radius: 12px !important;
            padding: 12px 20px !important;
            margin-top: 8px !important;
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            transition: all 0.3s ease !important;
        }

        .navbar__link--logout svg {
            stroke: #ffffff !important;
        }

        .navbar__link--logout:hover {
            background: #c82333 !important;
            border-color: #c82333 !important;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3) !important;
        }

        .navbar__link--logout:active {
            transform: translateY(0);
        }
    }

    @media (max-width: 768px) {

        /* Hide desktop login link on mobile as there is already a mobile header button */
        .navbar__item--desktop-login {
            display: none !important;
        }

        /* Hide desktop profile on mobile */
        .navbar__item--desktop-profile {
            display: none !important;
        }
    }

    /* Desktop Profile Link Styles (PC Only) */
    @media (min-width: 769px) {

        /* Hide mobile logout on desktop */
        .navbar__item--mobile-logout {
            display: none !important;
        }

        /* Desktop Profile Link Styling */
        .navbar__link--profile-desktop {
            display: flex !important;
            align-items: center !important;
            gap: 10px !important;
            padding: 8px 16px !important;
            border-radius: 25px !important;
            background: rgba(255, 255, 255, 0.9) !important;
            border: 2px solid rgba(102, 126, 234, 0.2) !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            text-decoration: none !important;
        }

        .navbar__link--profile-desktop:hover {
            background: rgba(102, 126, 234, 0.1) !important;
            border-color: #667eea !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.2) !important;
        }

        .navbar__link--profile-desktop:active {
            transform: translateY(0) !important;
        }

        .navbar__profile-avatar-desktop {
            width: 32px !important;
            height: 32px !important;
            border-radius: 50% !important;
            object-fit: cover !important;
            border: 2px solid rgba(102, 126, 234, 0.3) !important;
            flex-shrink: 0 !important;
        }

        .navbar__link--profile-desktop span {
            font-weight: 600 !important;
            color: var(--black) !important;
            font-size: 0.9rem !important;
        }
    }

    <style>
    /* Styling moved to top of file */
</style>
<script>
    // Mobile Menu Logic (Global & Robust)
    (function () {
        function initMobileMenu() {
            const btn = document.getElementById('hamburgerBtn');
            const menu = document.getElementById('navbarMenu');
            const body = document.body;

            // Define Global Toggle Function for onclick attributes
            window.toggleMobileMenu = function (e) {
                if (e) {
                    e.preventDefault();
                    e.stopPropagation();
                }

                // Re-fetch elements just in case
                const b = document.getElementById('hamburgerBtn');
                const m = document.getElementById('navbarMenu');

                if (!b || !m) {
                    console.warn('Mobile menu elements not found');
                    return;
                }

                const isActive = m.classList.contains('active');
                if (isActive) {
                    m.classList.remove('active');
                    b.classList.remove('active');
                    document.body.style.overflow = '';
                } else {
                    m.classList.add('active');
                    b.classList.add('active');
                    document.body.style.overflow = 'hidden';
                }
            };

            // Backup Listener
            if (btn) {
                // Remove old listeners if any to avoid duplicates
                btn.onclick = window.toggleMobileMenu;
            }

            // Close on Link Click
            if (menu) {
                const links = menu.querySelectorAll('a');
                links.forEach(link => {
                    link.addEventListener('click', () => {
                        const m = document.getElementById('navbarMenu');
                        const b = document.getElementById('hamburgerBtn');
                        if (m) m.classList.remove('active');
                        if (b) b.classList.remove('active');
                        document.body.style.overflow = '';
                    });
                });
            }
        }

        // Initialize on Load
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initMobileMenu);
        } else {
            initMobileMenu();
        }
    })();

    // Global cart-count badge updater (desktop navbar + mobile drawer)
    (function () {
        const cartCountEndpoint = "<?php echo $basePath; ?>/cart.php?action=get_count";

        function getCartLinks() {
            const links = [];

            // Desktop navbar cart links
            document.querySelectorAll('.navbar__cart-link').forEach(link => links.push(link));

            // Mobile drawer cart link
            const mobileCartLink = document.getElementById('dvMnavCartLink');
            if (mobileCartLink) {
                links.push(mobileCartLink);
            }

            return links;
        }

        function applyBadgeToLink(link, count) {
            if (!link) return;

            // Remove any existing badge
            const existingBadge = link.querySelector('.cart-count-badge');
            if (existingBadge) {
                existingBadge.remove();
            }

            if (!count || count <= 0) {
                return;
            }

            const badge = document.createElement('span');
            badge.className = 'cart-count-badge';
            badge.textContent = count;

            // Ensure parent is positioned for absolute badge (for non-desktop links)
            const computedPosition = window.getComputedStyle(link).position;
            if (computedPosition === 'static') {
                link.style.position = 'relative';
            }

            link.appendChild(badge);
        }

        function updateCartCount(count) {
            try {
                window.__lastCartCount = count;
                const links = getCartLinks();
                links.forEach(link => applyBadgeToLink(link, count));
            } catch (e) {
                console.error('Cart badge update failed:', e);
            }
        }

        // Expose globally so page scripts (menu, basket, etc.) can push updates
        window.updateCartCount = updateCartCount;

        function syncCartCountFromServer() {
            fetch(cartCountEndpoint, {
                method: 'GET',
                credentials: 'same-origin'
            })
                .then(resp => resp.ok ? resp.json() : null)
                .then(data => {
                    if (!data || !data.success) return;
                    updateCartCount(data.cart_count || 0);
                })
                .catch(err => {
                    console.error('Cart count sync error:', err);
                });
        }

        // Initial sync on ready + when page becomes visible again
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', syncCartCountFromServer);
        } else {
            syncCartCountFromServer();
        }

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                syncCartCountFromServer();
            }
        });
    })();
</script>