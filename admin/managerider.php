<?php
/**
 * Manage Riders - Admin Section
 * Modern interface for managing delivery rider accounts
 */

require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
requireAdminPin();

// Include database
global $pdo;

// Initialize message from session if exists
$message = $_SESSION['rider_success'] ?? '';
$error = $_SESSION['rider_error'] ?? '';
unset($_SESSION['rider_success'], $_SESSION['rider_error']);

$selectedBranchId = getAdminBranchId();

// Build redirect URL that preserves branch param
$branchRedirect = '';
if ($selectedBranchId && isSuperAdmin() && isset($_GET['branch'])) {
    $branchRedirect = '?branch=' . intval($_GET['branch']);
} elseif (isset($_POST['branch_param'])) {
    $bp = intval($_POST['branch_param']);
    if ($bp > 0) $branchRedirect = '?branch=' . $bp;
}


// Detect schema settings.
$riderSchema = [
    'table' => 'riders',
    'id' => 'id',
    'name_col' => 'username',
    'pass_col' => 'password',
    'phone_col' => 'phone',
    'status_col' => 'status'
];

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM riders");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (in_array('full_name', $cols))
        $riderSchema['name_col'] = 'full_name';
    elseif (in_array('name', $cols))
        $riderSchema['name_col'] = 'name';

    if (in_array('password_hash', $cols))
        $riderSchema['pass_col'] = 'password_hash';

    if (in_array('is_active', $cols))
        $riderSchema['status_col'] = 'is_active';

    if (!in_array('branch_id', $cols)) {
        $pdo->exec("ALTER TABLE riders ADD COLUMN branch_id INT NULL DEFAULT NULL AFTER id");
    }
    if (!in_array('can_take_available', $cols)) {
        $pdo->exec("ALTER TABLE riders ADD COLUMN can_take_available tinyint(1) NOT NULL DEFAULT 1 AFTER branch_id");
    }
} catch (Exception $e) { }

// Handle Rider Update (Password & Branch)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_password') {
    $rider_id = intval($_POST['rider_id'] ?? 0);
    $new_password = $_POST['new_password'] ?? '';
    $new_branch_id = isset($_POST['branch_id']) ? intval($_POST['branch_id']) : null;
    $can_take_available = 1; // Default to allowed pool access

    if ($rider_id <= 0) {
        $error = "Rider ID is missing.";
    } else {
        try {
            $updateFields = [];
            $updateParams = [];

            if (!empty($new_password)) {
                $updateFields[] = "`{$riderSchema['pass_col']}` = ?";
                $updateParams[] = password_hash($new_password, PASSWORD_DEFAULT);
            }

            if (isSuperAdmin() && $new_branch_id !== null) {
                $updateFields[] = "`branch_id` = ?";
                $updateParams[] = $new_branch_id;
            }

            // Update capability flag.
            $updateFields[] = "`can_take_available` = ?";
            $updateParams[] = $can_take_available;

            if (empty($updateFields)) {
                $error = "No changes provided.";
            } else {
                $updateParams[] = $rider_id;
                $sql = "UPDATE `riders` SET " . implode(", ", $updateFields) . " WHERE id = ?";
                
                if (!isSuperAdmin() && $selectedBranchId) {
                    $sql .= " AND branch_id = ?";
                    $updateParams[] = $selectedBranchId;
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute($updateParams);
                
                $_SESSION['rider_success'] = "Rider updated successfully.";
                header("Location: managerider.php" . $branchRedirect);
                exit;
            }
        } catch (PDOException $e) {
            $error = "Update Error: " . $e->getMessage();
        }
    }
}

// Handle Rider Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_rider') {
    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $can_take_available = 1; // Default to allowed pool access

    if (empty($name) || empty($phone) || empty($password)) {
        $error = "All fields are required.";
    } else {
        try {
            $passHash = password_hash($password, PASSWORD_DEFAULT);
            $query = "INSERT INTO `riders` (`{$riderSchema['name_col']}`, `{$riderSchema['phone_col']}`, `{$riderSchema['pass_col']}`, `{$riderSchema['status_col']}`, `branch_id`, `can_take_available`) VALUES (?, ?, ?, ?, ?, ?)";
            $statusVal = ($riderSchema['status_col'] === 'is_active') ? 1 : 'active';
            
            // Branch Assignment.
            $branchVal = $selectedBranchId ?: 1; 

            $stmt = $pdo->prepare($query);
            $stmt->execute([$name, $phone, $passHash, $statusVal, $branchVal, $can_take_available]);
            $_SESSION['rider_success'] = "Rider account for '$name' has been created successfully!";
            header("Location: managerider.php" . $branchRedirect);
            exit;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                $error = "A rider with this phone number already exists.";
            } else {
                $error = "Database Error: " . $e->getMessage();
            }
        }
    }
}

// Handle Status Toggle
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $newStatus = $_GET['toggle_status'];
    try {
        if ($selectedBranchId) {
            $stmt = $pdo->prepare("UPDATE `riders` SET `{$riderSchema['status_col']}` = ? WHERE id = ? AND branch_id = ?");
            $stmt->execute([$newStatus, $id, $selectedBranchId]);
        } else {
            $stmt = $pdo->prepare("UPDATE `riders` SET `{$riderSchema['status_col']}` = ? WHERE id = ?");
            $stmt->execute([$newStatus, $id]);
        }
        $_SESSION['rider_success'] = "Rider status updated.";
        header("Location: managerider.php" . $branchRedirect);
        exit;
    } catch (PDOException $e) {
        $error = "Update failed: " . $e->getMessage();
    }
}

// Handle Rider Deletion
if (isset($_GET['delete_rider'])) {
    $id = intval($_GET['delete_rider']);
    try {
        $pdo->beginTransaction();

        // Unlink any associated orders.
        $unlink = $pdo->prepare("UPDATE `orders` SET `rider_id` = NULL WHERE `rider_id` = ?");
        $unlink->execute([$id]);

        // 2. Perform the deletion (Branch Restricted)
        if ($selectedBranchId) {
            $stmt = $pdo->prepare("DELETE FROM `riders` WHERE id = ? AND branch_id = ?");
            $stmt->execute([$id, $selectedBranchId]);
        } else {
            $stmt = $pdo->prepare("DELETE FROM `riders` WHERE id = ?");
            $stmt->execute([$id]);
        }
        
        if ($stmt->rowCount() > 0) {
            $pdo->commit();
            $_SESSION['rider_success'] = "Rider account deleted successfully. Associated order history has been unlinked.";
        } else {
            $pdo->rollBack();
            $error = "Rider not found or unauthorized deletion.";
        }
        header("Location: managerider.php" . $branchRedirect);
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

// Get all riders with branch names
try {
    $ridersQuery = "SELECT r.*, b.name as branch_name 
                    FROM `riders` r 
                    LEFT JOIN branches b ON r.branch_id = b.id";
    
    if ($selectedBranchId) {
        $stmt = $pdo->prepare("$ridersQuery WHERE r.branch_id = ? ORDER BY r.id DESC");
        $stmt->execute([$selectedBranchId]);
        $riders = $stmt->fetchAll();
    } else {
        // If super admin hasn't selected a branch, show all.
        $stmt = $pdo->query("$ridersQuery ORDER BY r.id DESC");
        $riders = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    $riders = [];
    $error = "Riders table or branch relationship error. " . $e->getMessage();
}

$pageTitle = 'Manage Riders';
$currentAdmin = getCurrentAdmin();
$displayBranchName = $currentAdmin['branch_name'] ?? 'Main Branch';
$allBranches = [];

if (isSuperAdmin()) {
    $stmt = $pdo->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name ASC");
    $allBranches = $stmt->fetchAll();
}

// If Super Admin is filtering by branch
if (isSuperAdmin() && $selectedBranchId) {
    $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = ?");
    $stmt->execute([$selectedBranchId]);
    $displayBranchName = $stmt->fetchColumn() ?: 'Unknown Branch';
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="rider-admin-container">
    <!-- Stats Cards -->
    <div class="dashboard-stats" style="margin-bottom: 30px;">
        <div class="stat-card">
            <div class="stat-icon stat-icon-blue">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                    <circle cx="9" cy="7" r="4"></circle>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                </svg>
            </div>
            <div class="stat-details">
                <div class="stat-value"><?php echo count($riders); ?></div>
                <div class="stat-label">Total Riders (<?php echo htmlspecialchars($displayBranchName); ?>)</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon stat-icon-green">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
            </div>
            <div class="stat-details">
                <div class="stat-value">
                    <?php
                    echo count(array_filter($riders, function ($r) use ($riderSchema) {
                        $s = strtolower((string) $r[$riderSchema['status_col']]);
                        return $s === 'active' || $s === '1';
                    }));
                    ?>
                </div>
                <div class="stat-label">Active Runners</div>
            </div>
        </div>
    </div>

    <!-- Actions Header -->
    <div class="admin-actions-flex">
        <div class="filter-search-group">
            <div class="search-input-wrapper" style="margin-bottom: 0;">
                <svg class="search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="2">
                    <circle cx="11" cy="11" r="8" />
                    <path d="m21 21-4.35-4.35" />
                </svg>
                <input type="text" id="riderSearch" class="search-input" placeholder="Search by name, phone or ID...">
            </div>
        </div>

        <div class="action-buttons-group">
            <?php if (isSuperAdmin() && !empty($allBranches)): ?>
                <div class="branch-filter-wrapper">
                    <select id="branchFilter" class="modern-input branch-select-right" onchange="window.location.href='managerider.php?branch=' + this.value">
                        <option value="all">Across All Branches</option>
                        <?php foreach ($allBranches as $branch): ?>
                            <option value="<?php echo $branch['id']; ?>" <?php echo $selectedBranchId == $branch['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($branch['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            
            <button class="btn btn-primary create-btn" onclick="openAddRiderModal()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Create Rider Account
            </button>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success animate-fade-in">
            <div class="alert-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </div>
            <div class="alert-content"><?php echo htmlspecialchars($message); ?></div>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error animate-fade-in">
            <div class="alert-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
            </div>
            <div class="alert-content"><?php echo htmlspecialchars($error); ?></div>
        </div>
    <?php endif; ?>

    <!-- Riders Table -->
    <div class="orders-section"
        style="border: 1px solid var(--border-color); box-shadow: var(--shadow-sm); overflow: hidden;">
        <div class="table-responsive">
            <table class="rider-table">
                <thead>
                    <tr>
                        <th style="width: 80px;">ID</th>
                        <th>Rider Name</th>
                        <th>Phone Number</th>
                        <th>Branch</th>
                        <th>Duty Status</th>
                        <th style="padding-right: 20px; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="riderTableBody">
                    <?php if (empty($riders)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 60px 20px;">
                                <div style="color: var(--text-muted); margin-bottom: 15px;">
                                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="1">
                                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                        <circle cx="9" cy="7" r="4"></circle>
                                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                    </svg>
                                </div>
                                <h3 style="color: var(--text-primary); margin-bottom: 5px;">No Riders Found</h3>
                                <p style="color: var(--text-secondary); font-size: 14px;">Start by creating a new rider
                                    account.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($riders as $rider):
                            $isActive = (strtolower((string) $rider[$riderSchema['status_col']]) === 'active' || $rider[$riderSchema['status_col']] == '1');
                            ?>
                            <tr class="rider-row">
                                <td class="rider-id">#<?php echo $rider['id']; ?></td>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 12px;">
                                        <div class="avatar-sm">
                                            <?php echo strtoupper(substr($rider[$riderSchema['name_col']], 0, 1)); ?>
                                        </div>
                                        <div style="font-weight: 600; color: var(--text-primary);">
                                            <?php echo htmlspecialchars($rider[$riderSchema['name_col']]); ?>
                                        </div>
                                    </div>
                                </td>
                                <td style="color: var(--text-secondary); font-family: monospace; font-size: 14px;">
                                    <?php echo htmlspecialchars($rider[$riderSchema['phone_col']]); ?>
                                </td>
                                <td>
                                    <div style="font-size: 13px; font-weight: 700; color: #6366f1;">
                                        <?php echo htmlspecialchars($rider['branch_name'] ?: 'No Branch'); ?>
                                    </div>
                                </td>

                                <td>
                                    <?php if ($isActive): ?>
                                        <span class="badge-status badge-active">Active</span>
                                    <?php else: ?>
                                        <span class="badge-status badge-inactive">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: right;">
                                    <div style="display: flex; gap: 10px; justify-content: flex-end; align-items: center;">
                                        <button type="button" class="action-btn btn-view" title="Quick View Performance"
                                            onclick="openPerformanceModal(<?php echo $rider['id']; ?>, '<?php echo addslashes($rider[$riderSchema['name_col']]); ?>')">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                                style="margin-right: 6px;">
                                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                                <circle cx="12" cy="12" r="3"></circle>
                                            </svg>
                                            View
                                        </button>
                                        <button type="button" class="action-btn btn-info"
                                            onclick="openEditRiderModal(<?php echo $rider['id']; ?>, '<?php echo addslashes($rider[$riderSchema['name_col']]); ?>', <?php echo intval($rider['branch_id'] ?? 0); ?>, <?php echo $rider['can_take_available']; ?>)"
                                            title="Edit Rider Details">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                                style="margin-right: 6px;">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                            </svg>
                                            Edit
                                        </button>
                                        <?php if ($isActive): ?>
                                            <a href="?toggle_status=inactive&id=<?php echo $rider['id']; ?><?php echo $branchRedirect ? '&' . ltrim($branchRedirect, '?') : ''; ?>"
                                                class="action-btn btn-warn" title="Deactivate">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                    stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                                    style="margin-right: 6px;">
                                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                                </svg>
                                                Deactivate
                                            </a>
                                        <?php else: ?>
                                            <a href="?toggle_status=active&id=<?php echo $rider['id']; ?><?php echo $branchRedirect ? '&' . ltrim($branchRedirect, '?') : ''; ?>"
                                                class="action-btn btn-success" title="Activate">
                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                    stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                                    style="margin-right: 6px;">
                                                    <path d="M5 3l14 9-14 9V3z"></path>
                                                </svg>
                                                Activate
                                            </a>
                                        <?php endif; ?>
                                         <button type="button" class="action-btn btn-danger"
                                            onclick="confirmDeleteRider('?delete_rider=<?php echo $rider['id']; ?><?php echo $branchRedirect ? '&' . ltrim($branchRedirect, '?') : ''; ?>')"
                                            title="Delete Account">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"
                                                style="margin-right: 6px;">
                                                <polyline points="3 6 5 6 21 6"></polyline>
                                                <path
                                                    d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                                </path>
                                                <line x1="10" y1="11" x2="10" y2="17"></line>
                                                <line x1="14" y1="11" x2="14" y2="17"></line>
                                            </svg>
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Rider Performance Modal -->
<div id="performanceModal" class="modal-overlay">
    <div class="modal-card" style="max-width: 900px;">
        <div class="modal-card-header">
            <div>
                <h3 id="perfRiderName" style="margin:0;">Rider Performance</h3>
                <p id="perfRiderDateLabel"
                    style="font-size: 0.8rem; color: #64748b; margin-top: 4px; font-weight: 500;">Loading...</p>
            </div>
            <div style="display: flex; align-items: center; gap: 12px;">
                <div class="perf-modal-date-wrapper">
                    <input type="date" id="perfDateInput" onchange="loadRiderPerformance()">
                    <svg class="calendar-icon" width="16" height="16" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                </div>
                <button class="perf-modal-close" onclick="closePerformanceModal()" aria-label="Close">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                        stroke-linecap="round" stroke-linejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>
        </div>
        <div id="performanceModalBody" style="padding: 20px; min-height: 300px;">
            <!-- Content will be loaded via AJAX -->
        </div>
    </div>
</div>

<!-- Detailed Performance Sub-Modals (Rider Inspired Premium Design) -->
<div id="grabDetailsModal" class="edit-modal">
    <div class="edit-modal-backdrop" onclick="closeGrabModal()"></div>
    <div class="edit-modal-content">
        <div class="edit-modal-header" style="padding: 24px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: start; background: white; sticky: top;">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:40px; height:40px; border-radius:12px; background:#eef2ff; color:#6366f1; display:flex; align-items:center; justify-content:center;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7 4A2 2 0 0 0 21 16Z"></path><path d="m3.3 7 8.7 5 8.7-5"></path><path d="M12 22V12"></path></svg>
                </div>
                <div>
                    <h3 style="margin:0; font-size:1.1rem; font-weight:800; color:#1e293b;">Picked Orders</h3>
                    <p style="margin:2px 0 0; font-size:0.75rem; color:#64748b; font-weight:500;">Detailed list for selected date</p>
                </div>
            </div>
            <button class="edit-modal-close" onclick="closeGrabModal()">&times;</button>
        </div>
        <div id="grabDetailsBody" style="padding: 16px; background: #f8fafc; overflow-y: auto; flex-grow: 1; min-height: 200px;"></div>
        <div style="padding: 16px; border-top: 1px solid #f1f5f9; background: white;">
            <button class="action-btn" style="width:100%; min-height:48px; border-radius:14px; font-weight:800; background:#1e293b; color:white; border:none;" onclick="closeGrabModal()">Close Overview</button>
        </div>
    </div>
</div>

<div id="deliveredDetailsModal" class="edit-modal">
    <div class="edit-modal-backdrop" onclick="closeDeliveredModal()"></div>
    <div class="edit-modal-content">
        <div class="edit-modal-header" style="padding: 24px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: start; background: white; sticky: top;">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:40px; height:40px; border-radius:12px; background:#ecfdf5; color:#10b981; display:flex; align-items:center; justify-content:center;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                </div>
                <div>
                    <h3 style="margin:0; font-size:1.1rem; font-weight:800; color:#1e293b;">Delivered Orders</h3>
                    <p style="margin:2px 0 0; font-size:0.75rem; color:#64748b; font-weight:500;">Completed log for this date</p>
                </div>
            </div>
            <button class="edit-modal-close" onclick="closeDeliveredModal()">&times;</button>
        </div>
        <div id="deliveredDetailsBody" style="padding: 16px; background: #f8fafc; overflow-y: auto; flex-grow: 1; min-height: 200px;"></div>
        <div style="padding: 16px; border-top: 1px solid #f1f5f9; background: white;">
            <button class="action-btn" style="width:100%; min-height:48px; border-radius:14px; font-weight:800; background:#1e293b; color:white; border:none;" onclick="closeDeliveredModal()">Close Overview</button>
        </div>
    </div>
</div>

<div id="hiredKmDetailsModal" class="edit-modal">
    <div class="edit-modal-backdrop" onclick="closeHiredKmModal()"></div>
    <div class="edit-modal-content">
        <div class="edit-modal-header" style="padding: 24px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: start; background: white; sticky: top;">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:40px; height:40px; border-radius:12px; background:#fffbeb; color:#f59e0b; display:flex; align-items:center; justify-content:center;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                </div>
                <div>
                    <h3 style="margin:0; font-size:1.1rem; font-weight:800; color:#1e293b;">Hired KM Log</h3>
                    <p style="margin:2px 0 0; font-size:0.75rem; color:#64748b; font-weight:500;">Distance covered on delivered orders</p>
                </div>
            </div>
            <button class="edit-modal-close" onclick="closeHiredKmModal()">&times;</button>
        </div>
        <div id="hiredKmDetailsBody" style="padding: 16px; background: #f8fafc; overflow-y: auto; flex-grow: 1; min-height: 200px;"></div>
        <div style="padding: 16px; border-top: 1px solid #f1f5f9; background: white;">
            <button class="action-btn" style="width:100%; min-height:48px; border-radius:14px; font-weight:800; background:#1e293b; color:white; border:none;" onclick="closeHiredKmModal()">Close Overview</button>
        </div>
    </div>
</div>

<div id="cashDetailsModal" class="edit-modal">
    <div class="edit-modal-backdrop" onclick="closeCashModal()"></div>
    <div class="edit-modal-content">
        <div class="edit-modal-header" style="padding: 24px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: start; background: white; sticky: top;">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:40px; height:40px; border-radius:12px; background:#fef2f2; color:#ef4444; display:flex; align-items:center; justify-content:center;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="6" width="20" height="12" rx="2"></rect><circle cx="12" cy="12" r="2"></circle><path d="M6 12h.01M18 12h.01"></path></svg>
                </div>
                <div>
                    <h3 style="margin:0; font-size:1.1rem; font-weight:800; color:#1e293b;">Cash Collection Log</h3>
                    <p style="margin:2px 0 0; font-size:0.75rem; color:#64748b; font-weight:500;">Physical currency received on delivery</p>
                </div>
            </div>
            <button class="edit-modal-close" onclick="closeCashModal()">&times;</button>
        </div>
        <div id="cashDetailsBody" style="padding: 16px; background: #f8fafc; overflow-y: auto; flex-grow: 1; min-height: 200px;"></div>
        <div style="padding: 16px; border-top: 1px solid #f1f5f9; background: white;">
            <button class="action-btn" style="width:100%; min-height:48px; border-radius:14px; font-weight:800; background:#1e293b; color:white; border:none;" onclick="closeCashModal()">Close Overview</button>
        </div>
    </div>
</div>

<div id="onlineDetailsModal" class="edit-modal">
    <div class="edit-modal-backdrop" onclick="closeOnlineModal()"></div>
    <div class="edit-modal-content">
        <div class="edit-modal-header" style="padding: 24px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: start; background: white; sticky: top;">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:40px; height:40px; border-radius:12px; background:#eef2ff; color:#4f46e5; display:flex; align-items:center; justify-content:center;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="5" width="20" height="14" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>
                </div>
                <div>
                    <h3 style="margin:0; font-size:1.1rem; font-weight:800; color:#1e293b;">Online Collection Log</h3>
                    <p style="margin:2px 0 0; font-size:0.75rem; color:#64748b; font-weight:500;">Digital payments received securely</p>
                </div>
            </div>
            <button class="edit-modal-close" onclick="closeOnlineModal()">&times;</button>
        </div>
        <div id="onlineDetailsBody" style="padding: 16px; background: #f8fafc; overflow-y: auto; flex-grow: 1; min-height: 200px;"></div>
        <div style="padding: 16px; border-top: 1px solid #f1f5f9; background: white;">
            <button class="action-btn" style="width:100%; min-height:48px; border-radius:14px; font-weight:800; background:#1e293b; color:white; border:none;" onclick="closeOnlineModal()">Close Overview</button>
        </div>
    </div>
</div>

<div id="tipsDetailsModal" class="edit-modal">
    <div class="edit-modal-backdrop" onclick="closeTipsModal()"></div>
    <div class="edit-modal-content">
        <div class="edit-modal-header" style="padding: 24px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: start; background: white; sticky: top;">
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="width:40px; height:40px; border-radius:12px; background:#f5f3ff; color:#7c3aed; display:flex; align-items:center; justify-content:center;">
                    <span style="font-size: 14px; font-weight: 800;">Rs</span>
                </div>
                <div>
                    <h3 style="margin:0; font-size:1.1rem; font-weight:800; color:#1e293b;">Tips Collection Log</h3>
                    <p style="margin:2px 0 0; font-size:0.75rem; color:#64748b; font-weight:500;">Extra tips earned on deliveries</p>
                </div>
            </div>
            <button class="edit-modal-close" onclick="closeTipsModal()">&times;</button>
        </div>
        <div id="tipsDetailsBody" style="padding: 16px; background: #f8fafc; overflow-y: auto; flex-grow: 1; min-height: 200px;"></div>
        <div style="padding: 16px; border-top: 1px solid #f1f5f9; background: white;">
            <button class="action-btn" style="width:100%; min-height:48px; border-radius:14px; font-weight:800; background:#1e293b; color:white; border:none;" onclick="closeTipsModal()">Close Overview</button>
        </div>
    </div>
</div>

<div id="orderDetailModal" class="edit-modal">
    <div class="edit-modal-backdrop" onclick="closeOrderDetailModal()"></div>
    <div class="edit-modal-content">
        <div class="edit-modal-header" style="padding: 24px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: start; gap: 16px; background: white;">
            <div style="width: 40px; height: 40px; background: #f0f9ff; color: #0ea5e9; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                </svg>
            </div>
            <div style="flex-grow: 1;">
                <h3 style="margin:0; font-weight: 800; color: #1e293b; font-size: 1.1rem;">Order Receipt #<span id="modal-order-id-label"></span></h3>
                <p style="margin: 2px 0 0 0; font-size: 0.75rem; color: #64748b; font-weight:600;">Full Transaction Details</p>
            </div>
            <button class="edit-modal-close" onclick="closeOrderDetailModal()">&times;</button>
        </div>
        <div id="orderDetailContent" style="padding: 16px; background: #f8fafc; overflow-y: auto; flex-grow: 1; min-height: 250px;"></div>
        <div style="padding: 16px; border-top: 1px solid #f1f5f9; background: white;">
            <button class="action-btn" style="width:100%; min-height:48px; border-radius:14px; font-weight:800; background:#1e293b; color:white; border:none;" onclick="closeOrderDetailModal()">Close Details</button>
        </div>
    </div>
</div>

<!-- Add Rider Modal -->
<div id="addRiderModal" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-card-header">
            <h3>New Rider Account</h3>
            <button class="close-modal" onclick="closeAddRiderModal()">&times;</button>
        </div>
        <p style="margin: 15px 25px 5px; color: #6366f1; font-weight: 800; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; display: flex; align-items: center; gap: 6px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
            Linking to: <?php echo htmlspecialchars($displayBranchName); ?>
        </p>
        <form action="<?php echo $branchRedirect ? 'managerider.php' . $branchRedirect : ''; ?>" method="POST" class="modal-form">
            <input type="hidden" name="action" value="add_rider">
            <?php if ($branchRedirect): ?>
            <input type="hidden" name="branch_param" value="<?php echo intval($_GET['branch'] ?? 0); ?>">
            <?php endif; ?>

            <div class="form-group">
                <label>Rider Full Name</label>
                <input type="text" name="name" class="modern-input" placeholder="e.g. Rahul Sharma" required>
            </div>

            <div class="form-group">
                <label>Phone Number (Username)</label>
                <input type="tel" name="phone" id="rider_phone_input" class="modern-input" placeholder="98XXXXXXXX"
                    required pattern="[0-9]*" inputmode="numeric"
                    oninput="this.value = this.value.replace(/[^0-9]/g, '');">
                <small style="color: var(--text-muted); font-size: 11px;">Rider will use this to login. Only numbers
                    allowed.</small>
            </div>

            <div class="form-group">
                <label>Access Password</label>
                <input type="password" name="password" class="modern-input" placeholder="Set a secure password"
                    required>
            </div>



            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddRiderModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="padding-left: 30px; padding-right: 30px;">Create
                    Account</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Rider Modal -->
<div id="editRiderModal" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-card-header">
            <h3>Update Rider Details</h3>
            <button class="close-modal" onclick="closeEditRiderModal()">&times;</button>
        </div>
        <form action="<?php echo $branchRedirect ? 'managerider.php' . $branchRedirect : ''; ?>" method="POST" class="modal-form">
            <input type="hidden" name="action" value="update_password">
            <input type="hidden" name="rider_id" id="edit_rider_id">
            <?php if ($branchRedirect): ?>
            <input type="hidden" name="branch_param" value="<?php echo intval($_GET['branch'] ?? 0); ?>">
            <?php endif; ?>

            <div class="form-group" style="margin-bottom: 20px;">
                <label
                    style="display: block; margin-bottom: 8px; font-weight: 600; color: var(--text-secondary);">Updating
                    Password for:</label>
                <div id="edit_rider_name" style="font-size: 18px; font-weight: 700; color: #6366f1;"></div>
            </div>

            <div class="form-group">
                <label>Change Password (Leave blank to keep current)</label>
                <input type="password" name="new_password" class="modern-input" placeholder="Enter new password (optional)"
                    minlength="4">
                <small style="color: var(--text-muted); font-size: 11px;">Only fill if you want to reset their access password.</small>
            </div>

            <?php if (isSuperAdmin() && !empty($allBranches)): ?>
            <div class="form-group" style="margin-top: 15px;">
                <label>Assigned Branch</label>
                <select name="branch_id" id="edit_rider_branch_id" class="modern-input" style="appearance: auto;">
                    <?php foreach ($allBranches as $branch): ?>
                        <option value="<?php echo $branch['id']; ?>">
                            <?php echo htmlspecialchars($branch['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small style="color: var(--text-muted); font-size: 11px;">Move this rider to a different branch.</small>
            </div>
            <?php endif; ?>



            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditRiderModal()">Cancel</button>
                <button type="submit" class="btn btn-primary"
                    style="padding-left: 30px; padding-right: 30px; background: #6366f1;">Update Rider</button>
            </div>
        </form>
    </div>
</div>

<style>
    /* Actions Header Positioning */
    .admin-actions-flex {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        gap: 20px;
        flex-wrap: wrap;
    }

    .filter-search-group {
        flex: 1;
        min-width: 250px;
        max-width: 400px;
        margin-bottom: 0 !important;
    }

    .action-buttons-group {
        display: flex;
        gap: 15px;
        align-items: center;
        flex-wrap: wrap;
    }

    .branch-select-right {
        height: 46px; 
        margin-top: 0;
        border-radius: 14px; 
        padding: 0 40px 0 20px; 
        font-weight: 700; 
        color: #1e293b; 
        background-color: white;
        background-image: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20width%3D%2220%22%20height%3D%2220%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%2364748b%22%20stroke-width%3D%222.5%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%20xmlns%3D%22http%3A//www.w3.org/2000/svg%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C/polyline%3E%3C/svg%3E'); 
        background-repeat: no-repeat; 
        background-position: right 15px center; 
        background-size: 16px; 
        appearance: none; 
        border: 1.5px solid #e2e8f0; 
        min-width: 200px; 
        max-width: 260px; 
        text-overflow: ellipsis; 
        white-space: nowrap; 
        overflow: hidden;
    }

    .create-btn {
        height: 46px;
        padding: 0 24px;
        border-radius: 14px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
        white-space: nowrap;
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.25);
    }

    /* Premium Table Styling */
    .rider-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }

    .rider-table th {
        background: #f8fafc;
        padding: 16px 20px;
        text-align: left;
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
        border-bottom: 2px solid #f1f5f9;
    }

    .rider-row td {
        padding: 18px 20px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
        transition: background-color 0.1s ease;
    }

    /* Modern Scrolling Optimization for many rows */
    .rider-row {
        content-visibility: auto;
        contain-intrinsic-size: 1px 70px; /* Rough estimate of row height */
    }

    .rider-id {
        font-weight: 700;
        color: #94a3b8;
        font-size: 13px;
    }

    .avatar-sm {
        width: 36px;
        height: 36px;
        background: #eef2ff;
        color: #6366f1;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 14px;
        border: 1px solid #e0e7ff;
    }

    /* Badges */
    .badge-status {
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .badge-active {
        background: #dcfce7;
        color: #166534;
    }

    .badge-inactive {
        background: #f1f5f9;
        color: #475569;
    }

    /* Action Buttons */
    .action-btn {
        padding: 7px 15px;
        border-radius: 10px;
        font-size: 12px;
        font-weight: 700;
        text-decoration: none;
        transition: background-color 0.2s, color 0.2s, transform 0.2s; /* Specific properties */
        border: 1px solid transparent;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        line-height: 1;
        white-space: nowrap;
    }

    .action-btn svg {
        flex-shrink: 0;
    }

    .btn-success {
        background: #ecfdf5;
        color: #059669;
        border-color: #d1fae5;
    }

    .btn-success:hover {
        background: #10b981;
        color: white;
    }

    .btn-info {
        background: #eff6ff;
        color: #2563eb;
        border-color: #dbeafe;
    }

    .btn-info:hover {
        background: #3b82f6;
        color: white;
    }

    .btn-view {
        background: #f5f3ff;
        color: #7c3aed;
        border-color: #ddd6fe;
    }

    .btn-view:hover {
        background: #7c3aed;
        color: white;
    }

    .btn-warn {
        background: #fffbeb;
        color: #d97706;
        border-color: #fef3c7;
    }

    .btn-warn:hover {
        background: #f59e0b;
        color: white;
    }

    .btn-danger {
        background: #fef2f2;
        color: #dc2626;
        border-color: #fee2e2;
    }

    .btn-danger:hover {
        background: #ef4444;
        color: white;
    }

    /* Modal Styling */
    .modal-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.75); /* Darker solid background instead of blur */
        z-index: 1000;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .modal-overlay.active {
        display: flex;
    }

    .modal-card {
        background: white;
        width: 100%;
        max-width: 520px;
        border-radius: 20px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        overflow: hidden;
        animation: slideUp 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
        display: flex;
        flex-direction: column;
    }

    @keyframes slideUp {
        from {
            transform: translateY(30px);
            opacity: 0.5;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .modal-card-header {
        padding: 24px 30px;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-card-header h3 {
        font-size: 20px;
        font-weight: 700;
        color: #1e293b;
    }

    .close-modal {
        background: #f1f5f9;
        border: none;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        font-size: 20px;
        cursor: pointer;
        color: #64748b;
    }

    .modal-form {
        padding: 30px;
    }

    .modern-input {
        width: 100%;
        padding: 12px 16px;
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        font-size: 15px;
        transition: border-color 0.2s, box-shadow 0.2s;
        margin-top: 8px;
    }

    .modern-input:focus {
        border-color: #6366f1;
        outline: none;
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
    }

    .modal-footer {
        margin-top: 30px;
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    .animate-fade-in {
        animation: fadeIn 0.4s ease;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    /* Performance Modal Grid */
    .perf-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
        margin-bottom: 25px;
    }

    @media (min-width: 768px) {
        .perf-grid {
            grid-template-columns: repeat(4, 1fr);
        }
    }

    .perf-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        padding: 16px;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        transition: transform 0.2s;
    }

    .perf-card:hover {
        transform: translateY(-2px);
    }

    .perf-icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 12px;
    }

    .perf-label {
        font-size: 0.7rem;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 4px;
    }

    .perf-value {
        font-size: 1.1rem;
        font-weight: 800;
        color: #1e293b;
    }

    .perf-section-title {
        font-size: 0.9rem;
        font-weight: 800;
        color: #334155;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .perf-section-title::after {
        content: '';
        flex: 1;
        height: 1px;
        background: #e2e8f0;
    }

    .loading-spinner {
        width: 40px;
        height: 40px;
        border: 3px solid #f3f4f6;
        border-top: 3px solid #6366f1;
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 40px auto;
    }

    /* Detail Modal Styles */
    .edit-modal {
        display: none;
        position: fixed;
        inset: 0;
        z-index: 10000;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }

    .edit-modal.active {
        display: flex;
    }

    .edit-modal-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.7); /* Solid darkened background */
    }

    .edit-modal-content {
        position: relative;
        background: white;
        width: 100%;
        max-width: 600px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        animation: modalSlideUp 0.3s cubic-bezier(0.25, 1, 0.5, 1);
        border-radius: 28px;
        display: flex;
        flex-direction: column;
        margin: auto;
        overflow: hidden;
        max-height: 85vh;
        will-change: transform, opacity; /* Help browser optimize */
    }

    @keyframes modalSlideUp {
        from {
            transform: translateY(20px);
            opacity: 0;
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
        background: #f8fafc;
        color: #64748b;
    }

    .edit-modal-close:hover {
        background: #f1f5f9 !important;
        transform: rotate(90deg);
    }

    /* Optimized Stats Cards */
    .stat-card {
        contain: content;
        transition: transform 0.2s cubic-bezier(0.2, 0, 0.2, 1);
    }

    .clickable-perf-card {
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .clickable-perf-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        border-color: #6366f1;
    }

    .rider-loading-spinner {
        width: 32px;
        height: 32px;
        border: 4px solid #f1f5f9;
        border-top: 4px solid #6366f1;
        border-radius: 50%;
        margin: 0 auto;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        from {
            transform: rotate(0deg);
        }

        to {
            transform: rotate(360deg);
        }
    }

    /* Professional Modal Header Elements */
    .perf-modal-date-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }

    .perf-modal-date-wrapper input[type="date"] {
        appearance: none;
        -webkit-appearance: none;
        padding: 10px 42px 10px 16px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        font-size: 0.9rem;
        font-weight: 700;
        color: #1e293b;
        cursor: pointer;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
        font-family: inherit;
        z-index: 1;
    }

    .perf-modal-date-wrapper input[type="date"]:focus {
        border-color: #6366f1;
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        outline: none;
    }

    .perf-modal-date-wrapper .calendar-icon {
        position: absolute;
        right: 14px;
        color: #475569;
        pointer-events: none;
        z-index: 2;
    }

    .perf-modal-close {
        width: 38px;
        height: 38px;
        border-radius: 50%;
        background: #fef2f2;
        color: #ef4444;
        border: 1px solid #fee2e2;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        padding: 0;
    }

    .perf-modal-close:hover {
        background: #ef4444;
        color: white;
        transform: rotate(90deg) scale(1.1);
    }

    .perf-modal-close svg {
        transition: transform 0.2s;
    }
    /* Hardware Accelerated Animations */
    .modal-card, .edit-modal-content {
        will-change: transform, opacity;
        backface-visibility: hidden;
        transform: translateZ(0); /* Force GPU layer */
    }

    /* Prevent interaction during animations to save CPU */
    .modal-overlay.active .rider-admin-container {
        pointer-events: none;
    }

    /* Global Scroll Optimization */
    html {
        scroll-behavior: auto !important;
        overflow-x: hidden;
        scrollbar-gutter: stable; /* Crucial: Prevents layout shift (shaking) when scrollbar disappears */
    }
    
    body {
        -webkit-font-smoothing: antialiased;
        text-rendering: optimizeSpeed; /* Prioritize speed over pixel-perfect legibility */
    }

    .rider-admin-container {
        padding: 20px;
        max-width: 1400px;
        margin: 0 auto;
        contain: layout;
    }
</style>

<script>

    function closePerformanceModal() {
        document.getElementById('performanceModal').classList.remove('active');
        document.body.classList.remove('modal-open');
    }

    function openPerformanceModal(id, name) {
        currentRiderId = id;
        document.getElementById('perfRiderName').textContent = name;
        document.getElementById('perfDateInput').value = new Date().toISOString().split('T')[0];
        document.getElementById('performanceModal').classList.add('active');
        document.body.classList.add('modal-open');
        loadRiderPerformance();
    }

    function openAddRiderModal() {
        document.getElementById('addRiderModal').classList.add('active');
        document.body.classList.add('modal-open');
    }

    function closeAddRiderModal() {
        document.getElementById('addRiderModal').classList.remove('active');
        document.body.classList.remove('modal-open');
    }

    function openEditRiderModal(riderId, riderName, branchId, canTakeAvailable) {
    document.getElementById('edit_rider_id').value = riderId;
    document.getElementById('edit_rider_name').innerText = riderName;
    if (document.getElementById('edit_rider_branch_id')) {
        document.getElementById('edit_rider_branch_id').value = branchId;
    }
    document.getElementById('edit_can_take_available').checked = (canTakeAvailable == 1);
    document.getElementById('editRiderModal').classList.add('active');
    document.body.classList.add('modal-open');
}

    function closeEditRiderModal() {
        document.getElementById('editRiderModal').classList.remove('active');
        document.body.classList.remove('modal-open');
    }

    // Performance Detail Drill-down Functions
    function openGrabbedDetails(date) {
        const modal = document.getElementById('grabDetailsModal');
        const body = document.getElementById('grabDetailsBody');
        modal.classList.add('active');
        document.body.classList.add('modal-open');
        body.innerHTML = '<div style="padding:40px; text-align:center;"><div class="rider-loading-spinner"></div></div>';
        fetch(`api/rider_audit/get_grabbed_orders.php?date=${date}&rider_id=${currentRiderId}`).then(r => r.json()).then(d => {
            if (d.success) body.innerHTML = d.html;
            else body.innerHTML = `<div style="padding:20px; color:#ef4444;">${d.error}</div>`;
        });
    }

    function openDeliveredDetails(date) {
        const modal = document.getElementById('deliveredDetailsModal');
        const body = document.getElementById('deliveredDetailsBody');
        modal.classList.add('active');
        document.body.classList.add('modal-open');
        body.innerHTML = '<div style="padding:40px; text-align:center;"><div class="rider-loading-spinner"></div></div>';
        fetch(`api/rider_audit/get_delivered_orders.php?date=${date}&rider_id=${currentRiderId}`).then(r => r.json()).then(d => {
            if (d.success) body.innerHTML = d.html;
            else body.innerHTML = `<div style="padding:20px; color:#ef4444;">${d.error}</div>`;
        });
    }

    function openHiredKmDetails(date) {
        const modal = document.getElementById('hiredKmDetailsModal');
        const body = document.getElementById('hiredKmDetailsBody');
        modal.classList.add('active');
        document.body.classList.add('modal-open');
        body.innerHTML = '<div style="padding:40px; text-align:center;"><div class="rider-loading-spinner"></div></div>';
        fetch(`api/rider_audit/get_hired_km.php?date=${date}&rider_id=${currentRiderId}`).then(r => r.json()).then(d => {
            if (d.success) body.innerHTML = d.html;
            else body.innerHTML = `<div style="padding:20px; color:#ef4444;">${d.error}</div>`;
        });
    }

    function openTipsDetails(date) {
        const modal = document.getElementById('tipsDetailsModal');
        const body = document.getElementById('tipsDetailsBody');
        modal.classList.add('active');
        document.body.classList.add('modal-open');
        body.innerHTML = '<div style="padding:40px; text-align:center;"><div class="rider-loading-spinner"></div></div>';
        fetch(`api/rider_audit/get_tips_details.php?date=${date}&rider_id=${currentRiderId}`).then(r => r.json()).then(d => {
            if (d.success) body.innerHTML = d.html;
            else body.innerHTML = `<div style="padding:20px; color:#ef4444;">${d.error}</div>`;
        });
    }

    function openCashDetails(date) {
        const modal = document.getElementById('cashDetailsModal');
        const body = document.getElementById('cashDetailsBody');
        modal.classList.add('active');
        document.body.classList.add('modal-open');
        body.innerHTML = '<div style="padding:40px; text-align:center;"><div class="rider-loading-spinner"></div></div>';
        fetch(`api/rider_audit/get_cash_collect.php?date=${date}&rider_id=${currentRiderId}`).then(r => r.json()).then(d => {
            if (d.success) body.innerHTML = d.html;
            else body.innerHTML = `<div style="padding:20px; color:#ef4444;">${d.error}</div>`;
        });
    }

    function openOnlineDetails(date) {
        const modal = document.getElementById('onlineDetailsModal');
        const body = document.getElementById('onlineDetailsBody');
        modal.classList.add('active');
        document.body.classList.add('modal-open');
        body.innerHTML = '<div style="padding:40px; text-align:center;"><div class="rider-loading-spinner"></div></div>';
        fetch(`api/rider_audit/get_online_collect.php?date=${date}&rider_id=${currentRiderId}`).then(r => r.json()).then(d => {
            if (d.success) body.innerHTML = d.html;
            else body.innerHTML = `<div style="padding:20px; color:#ef4444;">${d.error}</div>`;
        });
    }

    function viewOrderDetails(orderId) {
        const modal = document.getElementById('orderDetailModal');
        const body = document.getElementById('orderDetailContent');
        const label = document.getElementById('modal-order-id-label');
        label.innerText = orderId;
        modal.classList.add('active');
        document.body.classList.add('modal-open');
        body.innerHTML = '<div style="padding:40px; text-align:center;"><div class="rider-loading-spinner"></div></div>';
        fetch(`api/rider_audit/get_order_details.php?order_id=${orderId}&rider_id=${currentRiderId}`).then(r => r.json()).then(d => {
            if (d.success) body.innerHTML = d.html;
            else body.innerHTML = `<div style="padding:20px; color:#ef4444;">${d.error}</div>`;
        });
    }

    function closeGrabModal() { 
        document.getElementById('grabDetailsModal').classList.remove('active');
        document.body.classList.remove('modal-open');
    }
    function closeDeliveredModal() { 
        document.getElementById('deliveredDetailsModal').classList.remove('active');
        document.body.classList.remove('modal-open');
    }
    function closeHiredKmModal() { 
        document.getElementById('hiredKmDetailsModal').classList.remove('active');
        document.body.classList.remove('modal-open');
    }
    function closeTipsModal() { 
        document.getElementById('tipsDetailsModal').classList.remove('active');
        document.body.classList.remove('modal-open');
    }
    function closeCashModal() { 
        document.getElementById('cashDetailsModal').classList.remove('active');
        document.body.classList.remove('modal-open');
    }
    function closeOnlineModal() { 
        document.getElementById('onlineDetailsModal').classList.remove('active');
        document.body.classList.remove('modal-open');
    }
    function closeOrderDetailModal() { 
        document.getElementById('orderDetailModal').classList.remove('active'); 
        document.body.classList.remove('modal-open');
    }

    function loadRiderPerformance() {
        const body = document.getElementById('performanceModalBody');
        const date = document.getElementById('perfDateInput').value;
        const label = document.getElementById('perfRiderDateLabel');

        body.innerHTML = '<div class="loading-spinner"></div>';
        label.textContent = 'Fetching performance for ' + date + '...';

        fetch(`api/rider_audit/get_rider_summary.php?rider_id=${currentRiderId}&date=${date}`)
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    const d = res.data.daily;
                    const m = res.data.monthly;
                    label.textContent = 'Showing Stats for ' + d.date_formatted;

                    body.innerHTML = `
                        <div class="perf-section-title">Total Performance: ${m.name}</div>
                        <div class="perf-grid">
                            <div class="perf-card">
                                <div class="perf-icon" style="background:#f5f3ff; color:#7c3aed;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                </div>
                                <div class="perf-label">Bike Km</div>
                                <div class="perf-value">${m.bike_km.toFixed(2)}</div>
                            </div>
                            <div class="perf-card">
                                <div class="perf-icon" style="background:#fffbeb; color:#d97706;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                </div>
                                <div class="perf-label">Hired Km</div>
                                <div class="perf-value">${m.hired_km.toFixed(2)}</div>
                            </div>
                            <div class="perf-card">
                                <div class="perf-icon" style="background:#ecfdf5; color:#059669;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                                </div>
                                <div class="perf-label">Delivered</div>
                                <div class="perf-value">${m.delivered}</div>
                            </div>
                            <div class="perf-card">
                                <div class="perf-icon" style="background:#eef2ff; color:#4f46e5;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                                </div>
                                <div class="perf-label">Monthly Tips</div>
                                <div class="perf-value">Rs ${m.tips.toLocaleString()}</div>
                            </div>
                        </div>

                        <div class="perf-section-title" style="margin-top:25px;">Daily Breakout: ${d.date_formatted}</div>
                        <div class="perf-grid">
                            <div class="perf-card clickable-perf-card" onclick="openGrabbedDetails('${date}')">
                                <div class="perf-icon" style="background:#eef2ff; color:#6366f1;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7 4A2 2 0 0 0 21 16Z"></path><path d="m3.3 7 8.7 5 8.7-5"></path><path d="M12 22V12"></path></svg>
                                </div>
                                <div class="perf-label">Today Grab</div>
                                <div class="perf-value">${d.picked}</div>
                            </div>
                            <div class="perf-card clickable-perf-card" onclick="openDeliveredDetails('${date}')">
                                <div class="perf-icon" style="background:#ecfdf5; color:#10b981;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                                </div>
                                <div class="perf-label">Delivered</div>
                                <div class="perf-value">${d.delivered}</div>
                            </div>
                            <div class="perf-card clickable-perf-card" onclick="openHiredKmDetails('${date}')">
                                <div class="perf-icon" style="background:#fffbeb; color:#f59e0b;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                </div>
                                <div class="perf-label">Hired Km</div>
                                <div class="perf-value">${d.km.toFixed(2)} km</div>
                            </div>
                             <div class="perf-card clickable-perf-card" onclick="openCashDetails('${date}')">
                                <div class="perf-icon" style="background:transparent;">
                                    <img src="../assets/cashlogo.jpg" alt="Cash" style="width:40px; height:40px; object-fit:contain; border-radius:8px;">
                                </div>
                                <div class="perf-label">Cash Collect</div>
                                <div class="perf-value" style="color:#ef4444;">Rs ${d.cash.toLocaleString()}</div>
                            </div>
                            <div class="perf-card clickable-perf-card" onclick="openOnlineDetails('${date}')">
                                <div class="perf-icon" style="background:transparent;">
                                    <img src="../assets/fonepay.png" alt="Fonepay" style="width:40px; height:40px; object-fit:contain; border-radius:8px;">
                                </div>
                                <div class="perf-label">Online Collect</div>
                                <div class="perf-value" style="color:#4f46e5;">Rs ${d.online.toLocaleString()}</div>
                            </div>
                            <div class="perf-card clickable-perf-card" onclick="openTipsDetails('${date}')">
                                <div class="perf-icon" style="background:#f5f3ff; color:#7c3aed;">
                                    <span style="font-size:14px; font-weight:800;">Rs</span>
                                </div>
                                <div class="perf-label">Tips</div>
                                <div class="perf-value" style="color:#7c3aed;">Rs ${d.tips.toLocaleString()}</div>
                            </div>
                             <div class="perf-card">
                                <div class="perf-icon" style="background:#f5f3ff; color:#8b5cf6;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                </div>
                                <div class="perf-label">Start Km</div>
                                <div class="perf-value">${d.start_km.toFixed(2)}</div>
                            </div>
                             <div class="perf-card">
                                <div class="perf-icon" style="background:#fdf2f8; color:#db2777;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                </div>
                                <div class="perf-label">Closing Km</div>
                                <div class="perf-value">${d.end_km.toFixed(2)}</div>
                            </div>
                             <div class="perf-card">
                                <div class="perf-icon" style="background:#f0fdf4; color:#16a34a;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                </div>
                                <div class="perf-label" style="color:#15803d;">Starting Duty Time</div>
                                <div class="perf-value" style="color:#15803d; font-size:1rem;">${d.duty_start_time || '–'}</div>
                            </div>
                             <div class="perf-card">
                                <div class="perf-icon" style="background:#fef2f2; color:#dc2626;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                </div>
                                <div class="perf-label" style="color:#b91c1c;">Closing Duty Time</div>
                                <div class="perf-value" style="color:#b91c1c; font-size:1rem;">${d.duty_end_time || '–'}</div>
                            </div>
                             <div class="perf-card" style="border: 1px solid #10b981 !important; background: #f0fdf4;">
                                <div class="perf-icon" style="background:#dcfce7; color:#166534;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <circle cx="12" cy="12" r="10"></circle>
                                        <polyline points="12 6 12 12 16 14"></polyline>
                                    </svg>
                                </div>
                                <div class="perf-label" style="color:#166534;">Total Bike Km</div>
                                <div class="perf-value" style="color: #166534; font-weight: 800;">${Math.max(0, d.end_km - d.start_km).toFixed(2)} km</div>
                            </div>
                        </div>
                    `;
                } else {
                    body.innerHTML = `<div style="text-align:center; padding:40px; color:#ef4444;">${res.error}</div>`;
                }
            })
            .catch(err => {
                body.innerHTML = '<div style="text-align:center; padding:40px; color:#ef4444;">Failed to load data.</div>';
            });
    }

    // Modal behavior enhancement
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeAddRiderModal();
            closeEditRiderModal();
            closePerformanceModal();
            closeGrabModal();
            closeDeliveredModal();
            closeHiredKmModal();
            closeTipsModal();
            closeCashModal();
            closeOnlineModal();
            closeOrderDetailModal();
        }
    });
    // Fast Debounced Smart Search Logic
    let searchTimeout;
    document.getElementById('riderSearch').addEventListener('input', function(e) {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            const searchValue = e.target.value.toLowerCase().trim();
            const searchKeywords = searchValue.split(/\s+/).filter(k => k.length > 0);
            const rows = document.querySelectorAll('.rider-row');
            
            requestAnimationFrame(() => {
                rows.forEach(row => {
                    if (searchValue === '') {
                        row.style.display = '';
                        return;
                    }

                    const riderText = row.innerText.toLowerCase();
                    const normalizedRiderText = riderText.replace(/[^a-z0-9\s]/g, '');

                    // Smart Match: Check if ALL keywords are found
                    const allMatch = searchKeywords.every(keyword => {
                        const cleanKeyword = keyword.replace(/[^a-z0-9]/g, '');
                        return riderText.includes(keyword) || (cleanKeyword.length > 0 && normalizedRiderText.includes(cleanKeyword));
                    });

                    row.style.display = allMatch ? '' : 'none';
                });
            });
        }, 150);
    });

    // Custom Confirm Logic
    function confirmDeleteRider(url) {
        showConfirm(
            'Delete Rider?', 
            'WARNING: Are you sure you want to delete this rider permanently? This action cannot be undone.', 
            'Yes, Delete',
            function() {
                window.location.href = url;
            }
        );
    }

    let confirmCallback = null;

    function showConfirm(title, message, btnText, callback) {
        document.getElementById('confirmTitle').textContent = title;
        document.getElementById('confirmMessage').textContent = message;
        const okBtn = document.getElementById('confirmOkBtn');
        okBtn.textContent = btnText;
        document.getElementById('confirmModal').style.display = 'flex';
        confirmCallback = callback;
    }

    function closeConfirmModal() {
        document.getElementById('confirmModal').style.display = 'none';
        confirmCallback = null;
    }

    document.getElementById('confirmOkBtn').onclick = function() {
        if (confirmCallback) confirmCallback();
        closeConfirmModal();
    };

    // Close on outside click
    window.onclick = function (event) {
        const confirmModal = document.getElementById('confirmModal');
        const addModal = document.getElementById('addRiderModal');
        const editModal = document.getElementById('editRiderModal');
        const perfModal = document.getElementById('performanceModal');

        if (event.target === confirmModal) closeConfirmModal();
        if (event.target === addModal) closeAddRiderModal();
        if (event.target === editModal) closeEditRiderModal();
        if (event.target === perfModal) closePerformanceModal();
    }
</script>

<!-- Custom Confirmation Modal -->
<div id="confirmModal" class="modal-overlay" style="z-index: 5000; display: none;">
    <div class="modal-card confirm-modal-box">
        <div class="confirm-icon">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
        </div>
        <h3 id="confirmTitle" style="margin-top: 0; margin-bottom: 12px; font-size: 22px; font-weight: 800; color: #0f172a;">Are you sure?</h3>
        <p id="confirmMessage" style="color: #64748b; font-size: 15px; line-height: 1.6; margin-bottom: 32px;">This action cannot be undone. Do you want to proceed?</p>
        <div style="display: flex; gap: 12px; justify-content: center;">
            <button type="button" class="btn btn-secondary" style="flex: 1;" onclick="closeConfirmModal()">Cancel</button>
            <button type="button" id="confirmOkBtn" class="btn btn-danger" style="flex: 1; background: #ef4444; color: white;">Yes, Delete</button>
        </div>
    </div>
</div>

<style>
/* Confirm Modal Specifics */
.confirm-modal-box {
    max-width: 400px !important;
    text-align: center;
    padding: 40px !important;
    border-radius: 32px !important;
    background: white;
}

.confirm-icon {
    width: 64px;
    height: 64px;
    background: #fee2e2;
    color: #ef4444;
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 24px;
    animation: iconBounce 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
}

@keyframes iconBounce {
    from { transform: scale(0); }
    to { transform: scale(1); }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>