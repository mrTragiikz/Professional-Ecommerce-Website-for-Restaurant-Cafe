<?php
require_once __DIR__ . '/auth.php';
requireAdminLogin();
$currentAdmin = getCurrentAdmin();

// --- NULL SAFETY: fall back to session data if DB query failed ---
if (!$currentAdmin) {
    $currentAdmin = [
        'id'          => $_SESSION['admin_id'] ?? 0,
        'username'    => $_SESSION['admin_username'] ?? 'Admin',
        'role'        => $_SESSION['admin_role'] ?? 'branch_admin',
        'branch_id'   => $_SESSION['admin_branch_id'] ?? null,
        'branch_name' => null,
        'last_login'  => null,
    ];
}

// --- BRANCH SYSTEMS INIT ---
$isAdmin    = ($currentAdmin['role'] ?? '') === 'super_admin';
$branchName = (string)($currentAdmin['branch_name'] ?? '');
// ---------------------------
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?>Admin Dashboard - JustKleek</title>
    <link rel="stylesheet" href="css/admin.css?v=<?php echo time() + 5; ?>">
    <link rel="stylesheet" href="css/responsive.css?v=<?php echo time() + 10; ?>">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link href="https://fonts.bunny.net/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>

<body>
    <div class="admin-wrapper">
        <!-- Sidebar -->
        <aside class="admin-sidebar">
            <div class="sidebar-header">
                <div class="logo">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none">
                        <path d="M12 2L2 7L12 12L22 7L12 2Z" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M2 17L12 22L22 17" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" />
                        <path d="M2 12L12 17L22 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" />
                    </svg>
                    <span>JustKleek</span>
                </div>
                
                <?php if (!$isAdmin && $branchName): ?>
                    <div style="margin-top: 8px; font-size: 11px; color: rgba(255,255,255,0.6); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; padding: 0 5px;">
                        Branch: <?php echo htmlspecialchars($branchName); ?>
                    </div>
                <?php endif; ?>
            </div>

            <nav class="sidebar-nav">
                <a href="admin_dashboard.php"
                    class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'admin_dashboard.php' ? 'active' : ''; ?>">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path d="M2.5 10L10 2.5L17.5 10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                            stroke-linejoin="round" />
                        <path
                            d="M4.16667 11.6667V17.5C4.16667 18.4205 4.91286 19.1667 5.83333 19.1667H8.33333V14.1667C8.33333 13.7064 8.70643 13.3333 9.16667 13.3333H10.8333C11.2936 13.3333 11.6667 13.7064 11.6667 14.1667V19.1667H14.1667C15.0871 19.1667 15.8333 18.4205 15.8333 17.5V11.6667"
                            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span>Dashboard</span>
                </a>

                <a href="allorder.php"
                    class="nav-item <?php echo basename($_SERVER['PHP_SELF']) === 'allorder.php' ? 'active' : ''; ?>">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path
                            d="M2.5 7.5H17.5M2.5 12.5H17.5M2.5 15.8333C2.5 16.2936 2.8731 16.6667 3.33333 16.6667H16.6667C17.1269 16.6667 17.5 16.2936 17.5 15.8333M2.5 4.16667C2.5 3.70643 2.8731 3.33333 3.33333 3.33333H16.6667C17.1269 3.33333 17.5 3.70643 17.5 4.16667"
                            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span>All Orders</span>
                </a>

                <a href="total_sales.php"
                    class="nav-item requires-pin <?php echo basename($_SERVER['PHP_SELF']) === 'total_sales.php' ? 'active' : ''; ?>">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path
                            d="M12.5 17.5H4.16667C3.24619 17.5 2.5 16.7538 2.5 15.8333V4.16667C2.5 3.24619 3.24619 2.5 4.16667 2.5H12.5"
                            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M10 5.83333H7.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                            stroke-linejoin="round" />
                        <path d="M10 9.16667H7.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                            stroke-linejoin="round" />
                        <path d="M7.5 12.5H9.16667" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                            stroke-linejoin="round" />
                        <path
                            d="M14.1666 14.1667C15.5473 14.1667 16.6666 13.0474 16.6666 11.6667C16.6666 10.286 15.5473 9.16667 14.1666 9.16667C12.7859 9.16667 11.6666 10.286 11.6666 11.6667C11.6666 13.0474 12.7859 14.1667 14.1666 14.1667Z"
                            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M17.5 17.5L15.8333 15.8333" stroke="currentColor" stroke-width="1.5"
                            stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <span>Statement Of Operation</span>
                </a>

                <a href="time.php"
                    class="nav-item requires-pin <?php echo basename($_SERVER['PHP_SELF']) === 'time.php' ? 'active' : ''; ?>">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                        stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                    <span>Time Selection</span>
                </a>

                <a href="delivery.php"
                    class="nav-item requires-pin <?php echo basename($_SERVER['PHP_SELF']) === 'delivery.php' ? 'active' : ''; ?>">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                        stroke-linecap="round" stroke-linejoin="round">
                        <rect x="1" y="3" width="15" height="13"></rect>
                        <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
                        <circle cx="5.5" cy="18.5" r="2.5"></circle>
                        <circle cx="18.5" cy="18.5" r="2.5"></circle>
                    </svg>
                    <span>Delivery Charge</span>
                </a>

                <a href="menu.php"
                    class="nav-item requires-pin <?php echo basename($_SERVER['PHP_SELF']) === 'menu.php' ? 'active' : ''; ?>">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                        stroke-linecap="round" stroke-linejoin="round">
                        <line x1="3" y1="12" x2="21" y2="12"></line>
                        <line x1="3" y1="6" x2="21" y2="6"></line>
                        <line x1="3" y1="18" x2="21" y2="18"></line>
                    </svg>
                    <span>Menu Items</span>
                </a>

                <a href="itemstock.php"
                    class="nav-item requires-pin <?php echo basename($_SERVER['PHP_SELF']) === 'itemstock.php' ? 'active' : ''; ?>">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path
                            d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z">
                        </path>
                        <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                        <line x1="12" y1="22.08" x2="12" y2="12"></line>
                    </svg>
                    <span>Item Stock</span>
                </a>

                <?php if ($isAdmin): ?>
                    <a href="gallery.php"
                        class="nav-item requires-pin <?php echo basename($_SERVER['PHP_SELF']) === 'gallery.php' ? 'active' : ''; ?>">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                            stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                            <polyline points="21 15 16 10 5 21"></polyline>
                        </svg>
                        <span>Gallery Image</span>
                    </a>
                <?php endif; ?>


                <a href="offer.php"
                    class="nav-item requires-pin <?php echo basename($_SERVER['PHP_SELF']) === 'offer.php' ? 'active' : ''; ?>">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
                        <line x1="7" y1="7" x2="7.01" y2="7"></line>
                    </svg>
                    <span>Make Offer</span>
                </a>

                <a href="combo.php"
                    class="nav-item requires-pin <?php echo basename($_SERVER['PHP_SELF']) === 'combo.php' ? 'active' : ''; ?>">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                        stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="12 2 2 7 12 12 22 7 12 2"></polygon>
                        <polyline points="2 17 12 22 22 17"></polyline>
                        <polyline points="2 12 12 17 22 12"></polyline>
                    </svg>
                    <span>Make Combo</span>
                </a>

                <a href="managerider.php"
                    class="nav-item requires-pin <?php echo basename($_SERVER['PHP_SELF']) === 'managerider.php' ? 'active' : ''; ?>">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                        stroke-linecap="round" stroke-linejoin="round">
                        <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                    <span>Manage Riders</span>
                </a>
<?php if ($isAdmin): ?>
                    <div style="margin: 15px 20px 5px; font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.8px;">Supervisor</div>
                    <a href="manage_branches.php"
                        class="nav-item requires-pin <?php echo basename($_SERVER['PHP_SELF']) === 'manage_branches.php' ? 'active' : ''; ?>">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                            <polyline points="9 22 9 12 15 12 15 22"></polyline>
                        </svg>
                        <span>Manage Branches</span>
                    </a>
                    <a href="manage_branch_admins.php"
                        class="nav-item requires-pin <?php echo basename($_SERVER['PHP_SELF']) === 'manage_branch_admins.php' ? 'active' : ''; ?>">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                            stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                        <span>Manage Admins</span>
                    </a>
                <?php endif; ?>
            </nav>

            <div class="sidebar-footer">
                <div class="admin-info">
                    <div class="admin-avatar">
                        <?php echo strtoupper(substr((string)($currentAdmin['username'] ?? 'A'), 0, 1)); ?>
                    </div>
                    <div class="admin-details">
                        <div class="admin-name"><?php echo htmlspecialchars((string)($currentAdmin['username'] ?? 'Admin')); ?></div>
                        <div class="admin-role"><?php echo $isAdmin ? 'Professor' : 'Staff'; ?></div>
                        <?php if (!$isAdmin): ?>
                            <div style="font-size: 10px; color: #6366f1; font-weight: 600;"><?php echo htmlspecialchars($branchName !== '' ? $branchName : 'Unknown Branch'); ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <a href="admin_detail.php"
                    class="nav-item requires-pin <?php echo basename($_SERVER['PHP_SELF']) === 'admin_detail.php' ? 'active' : ''; ?>">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                        stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path
                            d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z">
                        </path>
                    </svg>
                    <span>Admin Detail</span>
                </a>

                <a href="logout.php" class="logout-btn" id="logoutBtn">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path
                            d="M7.5 17.5H4.16667C3.24619 17.5 2.5 16.7538 2.5 15.8333V4.16667C2.5 3.24619 3.24619 2.5 4.16667 2.5H7.5"
                            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M13.3333 14.1667L17.5 10L13.3333 5.83333" stroke="currentColor" stroke-width="1.5"
                            stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M17.5 10H7.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                            stroke-linejoin="round" />
                    </svg>
                    <span>Logout</span>
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="admin-main">
            <header class="admin-header">
                <div class="header-left">
                    <button class="sidebar-toggle" id="sidebarToggle">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                            <path d="M3 12H21M3 6H21M3 18H21" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" />
                        </svg>
                    </button>
                    <h1 class="page-title"><?php echo isset($pageTitle) ? $pageTitle : 'Dashboard'; ?></h1>
                    
                    <?php if ($isAdmin && basename($_SERVER['PHP_SELF']) !== 'gallery.php'): ?>
                        <div class="header-branch-switcher" style="margin-left: 20px; display: flex; align-items: center;">
                            <form method="GET" action="" style="display: flex; align-items: center; position: relative;">
                                <?php foreach ($_GET as $key => $val): if ($key === 'branch') continue; ?>
                                    <input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($val); ?>">
                                <?php endforeach; ?>
                                <select name="branch" onchange="this.classList.add('loading'); this.form.submit()" 
                                        style="padding: 8px 36px 8px 16px; border-radius: 10px; border: 1.5px solid #e2e8f0; background: #fff; color: #0f172a; font-size: 13px; font-weight: 700; outline: none; cursor: pointer; appearance: none; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -1px rgba(0,0,0,0.03); transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); width: 100%; min-width: 180px;">
                                    <?php $selBranch = getAdminBranchId(); ?>
                                    <?php
                                    try {
                                        $header_branches_list = $pdo->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
                                        foreach ($header_branches_list as $b) {
                                            $isSelected = ($selBranch == $b['id']);
                                            echo "<option value='{$b['id']}' " . ($isSelected ? 'selected' : '') . ">🏠 " . htmlspecialchars($b['name']) . "</option>";
                                        }
                                    } catch (Exception $e) {}
                                    ?>
                                </select>
                                <svg style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #64748b;" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <polyline points="6 9 12 15 18 9"></polyline>
                                </svg>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="header-right" style="display: flex; align-items: center; gap: 15px;">
                    <div style="text-align: right; font-size: 11px; color: #64748b; line-height: 1.3;">
                        <div style="font-weight: 600;">Developed by Prabin Sharma</div>
                        <div style="font-size: 10px; color: #000000;">info: sharmaprabin160@gmail.com</div>
                    </div>
                    <div class="current-time-wrapper"
                        style="padding-left: 15px; border-left: 1px solid #e2e8f0; display: flex; flex-direction: column; justify-content: center; height: 36px;">
                        <div
                            style="font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; line-height: 1;">
                            Nepal Time</div>
                        <div id="currentTime"
                            style="font-size: 15px; font-weight: 700; color: #1e293b; font-family: 'Outfit', 'Inter', monospace; line-height: 1.2;">
                        </div>
                    </div>


                </div>
            </header>

            <div class="admin-content">