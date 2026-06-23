<?php
// Mobile-only hamburger + drawer (Da Vatti)
// NOTE: Button is now in navbar.php (id="dv-nav-open-btn")
// This file only provides the overlay and drawer structure.

$dvIsLoggedIn = false;
if (isset($isLoggedIn)) {
  $dvIsLoggedIn = (bool) $isLoggedIn;
} elseif (function_exists('isUserLoggedIn')) {
  $dvIsLoggedIn = (bool) isUserLoggedIn();
}

// Detect current page for active link styling
$dvCurrentPage = basename($_SERVER['PHP_SELF'] ?? '');
?>


<div id="dv-nav-overlay" class="dv-nav-overlay" aria-hidden="true"></div>

<aside id="dv-nav-drawer" class="dv-nav-drawer" aria-hidden="true">
  <div class="dv-nav-drawer-head">
    <div class="dv-nav-title" style="position: relative; width: 80px; height: 40px;">
      <img src="<?php echo $basePath; ?>/assets/headerkologo-Photoroom.png" alt="JustKleek"
        style="height: 80px; width: auto; object-fit: contain; position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%) rotate(-90deg);">
    </div>
    <button id="dv-nav-close-btn" class="dv-nav-close-btn" type="button" aria-label="Close navigation">
      &times;
    </button>
  </div>

  <nav class="dv-nav-links" aria-label="Mobile navigation">
    <a href="<?php echo $basePath; ?>/index"
      class="<?php echo $dvCurrentPage === 'index.php' ? 'nav-active' : ''; ?>">Home</a>

    <a href="<?php echo $basePath; ?>/menu"
      class="<?php echo $dvCurrentPage === 'menu.php' ? 'nav-active' : ''; ?>">Menu</a>

    <a href="<?php echo $basePath; ?>/cart"
      class="<?php echo in_array($dvCurrentPage, ['cart.php', 'basket.php'], true) ? 'nav-active' : ''; ?>">Cart</a>

    <a href="<?php echo $basePath; ?>/track-order"
      class="<?php echo $dvCurrentPage === 'order-tracking.php' ? 'nav-active' : ''; ?>">Track Order</a>

    <?php if ($dvIsLoggedIn): ?>
      <a href="<?php echo $basePath; ?>/profile"
        class="<?php echo $dvCurrentPage === 'profile.php' ? 'nav-active' : ''; ?>">Profile</a>
      <a class="dv-nav-logout" href="<?php echo $basePath; ?>/auth/logout.php">Logout</a>
    <?php else: ?>
      <a class="dv-nav-login <?php echo $dvCurrentPage === 'login.php' ? 'nav-active' : ''; ?>"
        href="<?php echo $basePath; ?>/auth/login.php">Login</a>
    <?php endif; ?>
  </nav>
</aside>
