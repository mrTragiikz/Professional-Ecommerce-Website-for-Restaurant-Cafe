<?php
if (!defined('RiderAppCore'))

    exit;
$rider_current_page = $rider_current_page ?? 'orders';
$rider_orders_filter = $rider_orders_filter ?? '';

// Check if we have context info (from a page that resolved RiderContext)
$is_admin_view = (isset($contextMode) && $contextMode === 'admin');
$admin_params = $is_admin_view ? '&rider_id=' . $riderId : '';
$admin_params_q = $is_admin_view ? '?rider_id=' . $riderId : '';
?>
<?php if ($is_admin_view): ?>
    <div style="background: #ef4444; color: white; text-align: center; padding: 10px; font-weight: 800; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; width: 100%; position: sticky; top: 0; z-index: 2000;">
        Admin Viewing Mode - Rider #<?php echo $riderId; ?>
    </div>
<?php endif; ?>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<div class="sidebar-nav" id="sidebarNav">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <div class="logo-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path>
                </svg>
            </div>
            <span>JustKleek</span>
        </div>
        <button class="sidebar-close" onclick="toggleSidebar()">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                stroke-linecap="round" stroke-linejoin="round">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
    </div>
    <div class="sidebar-menu">
        <div class="sidebar-section">
            <label>KM LOGS</label>
            <a href="daily_closing.php<?php echo $admin_params_q; ?>"
                class="sidebar-link <?php echo $rider_current_page === 'daily_closing' ? 'active' : ''; ?>">
                <div class="link-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg></div>
                <span>Bike Km Set</span>
            </a>
        </div>
        <div class="sidebar-section">
            <label>MAIN NAVIGATION</label>
            <a href="orders.php<?php echo $admin_params_q; ?>"
                class="sidebar-link <?php echo ($rider_current_page === 'orders' && in_array($rider_orders_filter, ['', 'available', 'my'], true)) ? 'active' : ''; ?>">
                <div class="link-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <rect x="1" y="3" width="15" height="13"></rect>
                        <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
                        <circle cx="5.5" cy="18.5" r="2.5"></circle>
                        <circle cx="18.5" cy="18.5" r="2.5"></circle>
                    </svg></div>
                <span>Delivery</span>
            </a>
            <a href="orders.php?filter=delivered<?php echo $admin_params; ?>"
                class="sidebar-link <?php echo ($rider_current_page === 'orders' && $rider_orders_filter === 'delivered') ? 'active' : ''; ?>">
                <div class="link-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                    </svg></div>
                <span>Completed</span>
            </a>
        </div>
        <div class="sidebar-section">
            <label>MY DETAILS</label>
            <a href="my_details.php<?php echo $admin_params_q; ?>"
                class="sidebar-link <?php echo $rider_current_page === 'my_details' ? 'active' : ''; ?>">
                <div class="link-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg></div>
                <span>My Details</span>
            </a>
        </div>

            <?php if ($is_admin_view): ?>
                <a href="../managerider.php" class="sidebar-link logout-btn" style="background: #1e293b;">
                    <div class="link-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M19 12H5M12 19l-7-7 7-7" />
                        </svg></div>
                    <span>Back to Dashboard</span>
                </a>
            <?php else: ?>
                <a href="logout.php" class="sidebar-link logout-btn">
                    <div class="link-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                            <polyline points="16 17 21 12 16 7"></polyline>
                            <line x1="21" y1="12" x2="9" y2="12"></line>
                        </svg></div>
                    <span>Sign Out</span>
                </a>
            <?php endif; ?>
        </div>
    </div>

<script>
    function toggleSidebar() {
        var nav = document.getElementById('sidebarNav');
        var overlay = document.getElementById('sidebarOverlay');
        if (nav) nav.classList.toggle('active');
        if (overlay) overlay.classList.toggle('active');
    }
</script>