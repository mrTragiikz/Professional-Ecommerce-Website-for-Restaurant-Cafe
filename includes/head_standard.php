<?php
// includes/head_standard.php
// Critical CSS and Standard Head Elements to prevent FOUC
$basePath = isset($basePath) ? $basePath : ''; // Ensure basePath is defined
?>
<meta charset="UTF-8">
<meta name="viewport"
    content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">

<!-- Fonts Preconnect -->
<link rel="preconnect" href="https://fonts.bunny.net">
<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
<link
    href="https://fonts.bunny.net/css2?family=Cormorant+Garamond:wght@300;400;500;600;700&family=Montserrat:wght@300;400;500;600&family=Inter:wght@400;500&family=Poppins:wght@600;700&display=swap"
    rel="stylesheet">


<!-- Critical CSS Inlined for Instant Loading -->
<style>
    :root {
        --orange: #FFA53B;
        --black: #1E1E1E;
        --gray: #6F6F6F;
        --white: #FFFFFF;
        --shadow: 0 12px 32px rgba(0, 0, 0, 0.1);
        --z-navbar: 11000;
    }

    *,
    *::before,
    *::after {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
    }

    html {
        overflow-x: hidden;
        width: 100%;
        touch-action: manipulation;
        -webkit-text-size-adjust: 100%;
    }

    body {
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
        background: var(--white);
        color: var(--black);
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        padding: 0;
        width: 100%;
        -webkit-tap-highlight-color: transparent;
    }

    .navbar {
        width: 100%;
        background: linear-gradient(135deg,
            rgba(34, 139, 80, 0.95) 0%,
            rgba(40, 160, 90, 0.92) 25%,
            rgba(30, 130, 70, 0.90) 50%,
            rgba(40, 160, 90, 0.92) 75%,
            rgba(34, 139, 80, 0.95) 100%) !important;
        box-shadow: 0 4px 20px rgba(20, 100, 50, 0.25) !important;
        border-bottom: 1px solid rgba(60, 180, 100, 0.45) !important;
        position: sticky;
        top: 0;
        z-index: var(--z-navbar);
        padding: 16px 0;
        display: block;
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
    }

    .navbar__container {
        width: min(1200px, 100%);
        margin: 0 auto;
        padding: 0 24px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .navbar__logo {
        font-family: 'Poppins', sans-serif;
        font-size: 24px;
        font-weight: 700;
        color: var(--orange);
        display: flex;
        align-items: center;
    }

    .logo__text {
        background: linear-gradient(135deg, var(--orange), #ff8c1a);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        display: inline-block;
        font-size: 24px;
        font-weight: 700;
    }

    /* Global layout stability */
    .page-menu,
    .menu-page,
    body {
        opacity: 1 !important;
        visibility: visible !important;
    }
</style>

<!-- Optimize Main CSS Delivery -->
<link rel="preload" href="<?php echo $basePath; ?>/css/style.css?v=<?php echo time(); ?>" as="style">
<link rel="stylesheet" href="<?php echo $basePath; ?>/css/style.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="<?php echo $basePath; ?>/assets/css/dv_mobile_nav_v3.css?v=<?php echo time(); ?>">