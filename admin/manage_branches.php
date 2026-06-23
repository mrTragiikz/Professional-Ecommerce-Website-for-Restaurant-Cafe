<?php
/**
 * Manage Branches - Super Admin Section
 */

require_once __DIR__ . '/includes/auth.php';
requireSuperAdmin(); // Specialized auth check for Super Admin

// Include database
global $pdo;

$message = $_SESSION['branch_success'] ?? '';
$error = $_SESSION['branch_error'] ?? '';
unset($_SESSION['branch_success'], $_SESSION['branch_error']);

// Ensure columns exist
try {
    // Check if columns exist first to avoid errors on standard MySQL
    $stmt = $pdo->query("SHOW COLUMNS FROM branches");
    $existing_cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $existing_cols = array_map('strtolower', $existing_cols);

    if (!in_array('slug', $existing_cols)) {
        $pdo->exec("ALTER TABLE branches ADD COLUMN slug VARCHAR(100) AFTER name");
    }
    if (!in_array('address', $existing_cols)) {
        $pdo->exec("ALTER TABLE branches ADD COLUMN address TEXT AFTER slug");
    }
    if (!in_array('phone', $existing_cols)) {
        $pdo->exec("ALTER TABLE branches ADD COLUMN phone VARCHAR(50) AFTER address");
    }
    if (!in_array('latitude', $existing_cols)) {
        $pdo->exec("ALTER TABLE branches ADD COLUMN latitude DECIMAL(10, 8) AFTER phone");
    }
    if (!in_array('longitude', $existing_cols)) {
        $pdo->exec("ALTER TABLE branches ADD COLUMN longitude DECIMAL(11, 8) AFTER latitude");
    }
    if (!in_array('is_active', $existing_cols)) {
        $pdo->exec("ALTER TABLE branches ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER longitude");
    }

    // Also ensure branch_id exists in admins
    $stmt = $pdo->query("SHOW COLUMNS FROM admins");
    $admin_cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $admin_cols = array_map('strtolower', $admin_cols);
    if (!in_array('branch_id', $admin_cols)) {
        $pdo->exec("ALTER TABLE admins ADD COLUMN branch_id INT AFTER password_hash");
    }
} catch (Exception $e) {
    // Silently continue if migration fails
    $error = "Migration notice: " . $e->getMessage();
}

// Handle Branch Creation/Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_branch' || $_POST['action'] === 'edit_branch') {
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $latitude = (isset($_POST['latitude']) && $_POST['latitude'] !== '') ? floatval($_POST['latitude']) : null;
        $longitude = (isset($_POST['longitude']) && $_POST['longitude'] !== '') ? floatval($_POST['longitude']) : null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $branchId = intval($_POST['branch_id'] ?? 0);

        if (empty($name) || empty($slug)) {
            $error = "Name and Slug are required.";
        } else {
            try {
                $pdo->beginTransaction();

                if ($_POST['action'] === 'add_branch') {
                    $adminUsername = trim($_POST['admin_username'] ?? '');
                    $adminPassword = $_POST['admin_password'] ?? '';

                    if (empty($adminUsername) || empty($adminPassword)) {
                        throw new Exception("Branch Admin username and password are required.");
                    }

                    // Insert Branch using named parameters for safety
                    $stmt = $pdo->prepare("INSERT INTO branches (name, slug, address, phone, latitude, longitude, is_active) 
                                           VALUES (:name, :slug, :address, :phone, :lat, :lng, :active)");
                    $stmt->execute([
                        'name'   => $name,
                        'slug'   => $slug,
                        'address'=> $address,
                        'phone'  => $phone,
                        'lat'    => $latitude,
                        'lng'    => $longitude,
                        'active' => $isActive
                    ]);
                    $newBranchId = $pdo->lastInsertId();

                    // Insert Branch Admin
                    $passwordHash = password_hash($adminPassword, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash, role, branch_id) 
                                           VALUES (:user, :pass, 'branch_admin', :bid)");
                    $stmt->execute([
                        'user' => $adminUsername,
                        'pass' => $passwordHash,
                        'bid'  => $newBranchId
                    ]);

                    $_SESSION['branch_success'] = "Branch '$name' and admin '$adminUsername' created successfully.";
                } else {
                    $stmt = $pdo->prepare("UPDATE branches SET name = :name, slug = :slug, address = :address, phone = :phone, 
                                           latitude = :lat, longitude = :lng, is_active = :active WHERE id = :id");
                    $stmt->execute([
                        'name'   => $name,
                        'slug'   => $slug,
                        'address'=> $address,
                        'phone'  => $phone,
                        'lat'    => $latitude,
                        'lng'    => $longitude,
                        'active' => $isActive,
                        'id'     => $branchId
                    ]);
                    
                    // Handle Admin Credentials Update during branch edit
                    $adminUsername = trim($_POST['admin_username'] ?? '');
                    $adminPassword = $_POST['admin_password'] ?? '';
                    
                    if (!empty($adminUsername) || !empty($adminPassword)) {
                        // Check if an admin exists for this branch
                        $stmtAdmin = $pdo->prepare("SELECT id FROM admins WHERE branch_id = ? AND role = 'branch_admin'");
                        $stmtAdmin->execute([$branchId]);
                        $adminRow = $stmtAdmin->fetch();
                        
                        if ($adminRow) {
                            // Update existing admin
                            if (!empty($adminUsername) && !empty($adminPassword)) {
                                $passwordHash = password_hash($adminPassword, PASSWORD_BCRYPT);
                                $stmtUpdate = $pdo->prepare("UPDATE admins SET username = ?, password_hash = ? WHERE branch_id = ? AND role = 'branch_admin'");
                                $stmtUpdate->execute([$adminUsername, $passwordHash, $branchId]);
                            } elseif (!empty($adminUsername)) {
                                $stmtUpdate = $pdo->prepare("UPDATE admins SET username = ? WHERE branch_id = ? AND role = 'branch_admin'");
                                $stmtUpdate->execute([$adminUsername, $branchId]);
                            } elseif (!empty($adminPassword)) {
                                $passwordHash = password_hash($adminPassword, PASSWORD_BCRYPT);
                                $stmtUpdate = $pdo->prepare("UPDATE admins SET password_hash = ? WHERE branch_id = ? AND role = 'branch_admin'");
                                $stmtUpdate->execute([$passwordHash, $branchId]);
                            }
                        } else if (!empty($adminUsername) && !empty($adminPassword)) {
                            // Needs both to create new admin if somehow branch was created without one
                            $passwordHash = password_hash($adminPassword, PASSWORD_BCRYPT);
                            $stmtInsert = $pdo->prepare("INSERT INTO admins (username, password_hash, role, branch_id) VALUES (?, ?, 'branch_admin', ?)");
                            $stmtInsert->execute([$adminUsername, $passwordHash, $branchId]);
                        }
                    }

                    $_SESSION['branch_success'] = "Branch '$name' updated successfully.";
                }

                $pdo->commit();
                header("Location: manage_branches.php");
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $error = "A branch with this slug or admin with this username already exists.";
                } else {
                    $error = "Error: " . $e->getMessage();
                }
            }
        }
    }
}

// Handle Branch Deletion
if (isset($_GET['delete_branch'])) {
    $id = intval($_GET['delete_branch']);
    try {
        $pdo->beginTransaction();
        
        // 1. Fetch and delete menu item images physically
        $imgStmt = $pdo->prepare("SELECT image_path FROM menu_items WHERE restaurant_id = ?");
        $imgStmt->execute([$id]);
        $images = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($images as $imgPath) {
            if (!empty($imgPath)) {
                $fullPath = __DIR__ . '/../' . $imgPath;
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
            }
        }

        // 2. Fetch and delete user profile pictures physically
        $userImgStmt = $pdo->prepare("SELECT profile_picture FROM users WHERE branch_id = ?");
        $userImgStmt->execute([$id]);
        $userImages = $userImgStmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($userImages as $uImgPath) {
            if (!empty($uImgPath)) {
                $fullPath = __DIR__ . '/../' . $uImgPath;
                if (file_exists($fullPath)) {
                    @unlink($fullPath);
                }
            }
        }

        // 3. Delete branch-specific JSON configuration files
        $configFiles = [
            __DIR__ . '/../secure_config/restaurant_hours_branch_' . $id . '.json',
            __DIR__ . '/../secure_config/delivery_settings_branch_' . $id . '.json'
        ];
        foreach ($configFiles as $cf) {
            if (file_exists($cf)) {
                @unlink($cf);
            }
        }

        // 4. Delete payment transactions associated with orders of this branch
        // (Must be done manually as it has ON DELETE NO ACTION)
        $pdo->prepare("DELETE FROM payment_transactions WHERE order_id IN (SELECT id FROM orders WHERE restaurant_id = ?)")->execute([$id]);
        
        // 4. Delete orders associated with this branch 
        // (CASCADE will handle order_items and payments)
        $pdo->prepare("DELETE FROM orders WHERE restaurant_id = ?")->execute([$id]);
        
        // 5. Delete daily sales summary for this branch
        $pdo->prepare("DELETE FROM daily_sales_summary WHERE branch_id = ?")->execute([$id]);

        // 6. Delete menu items and categories
        $pdo->prepare("DELETE FROM menu_items WHERE restaurant_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM menu_categories WHERE restaurant_id = ?")->execute([$id]);

        // 7. Delete associated accounts (admins, riders, customers)
        // (CASCADE on riders/users will handle audit logs, closings, cart items, etc.)
        $pdo->prepare("DELETE FROM admins WHERE branch_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM riders WHERE branch_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM users WHERE branch_id = ?")->execute([$id]);
        
        // 8. Finally, delete the branch record
        $pdo->prepare("DELETE FROM branches WHERE id = ?")->execute([$id]);
        
        $pdo->commit();
        
        $_SESSION['branch_success'] = "Branch and all associated data (Menus, Users, Orders) dismantled successfully!";
        header("Location: manage_branches.php");
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['branch_error'] = "Error dismantling branch: " . $e->getMessage();
        header("Location: manage_branches.php");
        exit;
    }
}

// Get all branches for dropdown (using a unique name to avoid header collision)
$stmt = $pdo->query("SELECT id, name FROM branches WHERE is_active = 1");
$branchListForDropdown = $stmt->fetchAll();

// Get branches and admins
// Select columns
$stmt = $pdo->query("
    SELECT b.id, b.name, b.slug, b.address, b.phone, b.latitude, b.longitude, b.is_active,
           (SELECT username FROM admins WHERE branch_id = b.id AND role = 'branch_admin' LIMIT 1) as admin_username 
    FROM branches b 
    ORDER BY b.id ASC
");
$allBranchesList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Mapping branch data

$pageTitle = 'Manage Branches';
require_once __DIR__ . '/includes/header.php';
?>

<div class="rider-admin-container">
    <div class="admin-actions-flex">
        <div class="header-with-subtitle">
            <h2 style="margin: 0; color: var(--text-primary); font-size: 24px; font-weight: 700;">Manage Branches</h2>
            <p style="margin: 5px 0 0; color: var(--text-secondary); font-size: 14px;">Create and monitor all restaurant branch locations</p>
        </div>
        <button class="btn btn-primary create-btn" onclick="openAddBranchModal()" style="display: flex; align-items: center; gap: 8px; padding: 12px 20px; border-radius: 12px; font-weight: 600; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.2);">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
            Add New Branch
        </button>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success animate-fade-in" style="margin-bottom: 25px;">
            <div class="alert-content" style="display: flex; align-items: center; gap: 10px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
                <?php echo htmlspecialchars($message); ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error animate-fade-in" style="margin-bottom: 25px;">
            <div class="alert-content" style="display: flex; align-items: center; gap: 10px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
                <?php echo htmlspecialchars($error); ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="modern-card" style="background: white; border-radius: 20px; border: 1px solid rgba(0,0,0,0.06); box-shadow: 0 10px 30px rgba(0,0,0,0.04); overflow: hidden;">
        <div class="table-responsive">
            <table class="rider-table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 1px solid #edf2f7;">
                        <th style="padding: 18px 25px; text-align: left; font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 60px;">ID</th>
                        <th style="padding: 18px 25px; text-align: left; font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Branch Details</th>
                        <th style="padding: 18px 25px; text-align: left; font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Admin Account</th>
                        <th style="padding: 18px 25px; text-align: left; font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Access URLs</th>
                        <th style="padding: 18px 25px; text-align: left; font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Contact</th>
                        <th style="padding: 18px 25px; text-align: left; font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 100px;">Status</th>
                        <th style="padding: 18px 25px; text-align: right; font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; width: 140px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($allBranchesList)): ?>
                        <tr>
                            <td colspan="7" style="padding: 40px; text-align: center; color: var(--text-secondary);">No branches found.</td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($allBranchesList as $branch): 
                        $statusText = ($branch['is_active'] ?? 1) ? 'Active' : 'Inactive';
                        $statusColor = ($branch['is_active'] ?? 1) ? '#059669' : '#e11d48';
                        $statusBg = ($branch['is_active'] ?? 1) ? '#ecfdf5' : '#fff1f2';
                    ?>
                        <tr class="rider-row" style="border-bottom: 1px solid #f1f5f9; transition: background 0.2s;">
                            <td style="padding: 20px 25px; color: #94a3b8; font-weight: 600;">#<?php echo $branch['id']; ?></td>
                            <td style="padding: 20px 25px;">
                                <div style="font-weight: 700; color: #1e293b; font-size: 16px;"><?php echo htmlspecialchars($branch['name'] ?? 'Unnamed'); ?></div>
                                <div style="font-size: 13px; color: #64748b; margin-top: 4px; display: flex; align-items: center; gap: 4px;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                                    <?php echo htmlspecialchars((trim($branch['address'] ?? '') ?: 'No address')); ?>
                                </div>
                            </td>
                            <td style="padding: 20px 25px;">
                                <div style="display: flex; flex-direction: column; gap: 4px;">
                                    <div style="font-weight: 600; color: #334155; font-size: 14px;">@<?php echo htmlspecialchars((trim($branch['admin_username'] ?? '') ?: 'No admin')); ?></div>
                                    <div style="font-size: 11px; color: #94a3b8;">Branch Administrator</div>
                                </div>
                            </td>
                            <td style="padding: 20px 25px;">
                                <div style="display: flex; flex-direction: column; gap: 6px;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <span style="font-size: 9px; font-weight: 800; color: #6366f1; text-transform: uppercase; background: #eef2ff; padding: 2px 6px; border-radius: 4px; border: 1px solid #e0e7ff;">Admin</span>
                                        <code style="font-size: 12px; color: #4338ca; background: #f5f7ff; padding: 2px 6px; border-radius: 4px; border: 1px solid #dee2ff;">/admin/?branch=<?php echo $branch['id']; ?></code>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <span style="font-size: 9px; font-weight: 800; color: #10b981; text-transform: uppercase; background: #ecfdf5; padding: 2px 6px; border-radius: 4px; border: 1px solid #d1fae5;">Order</span>
                                        <code style="font-size: 12px; color: #065f46; background: #f0fdf4; padding: 2px 6px; border-radius: 4px; border: 1px solid #dcfce7;">/?branch=<?php echo htmlspecialchars(($branch['slug'] ?? '') ?: ($branch['id'] ?? '')); ?></code>
                                    </div>
                                </div>
                            </td>
                            <td style="padding: 20px 25px;">
                                <div style="display: flex; align-items: center; gap: 8px; color: #475569; font-weight: 500;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                                    <?php echo htmlspecialchars((trim($branch['phone'] ?? '') ?: 'N/A')); ?>
                                </div>
                            </td>
                            <td style="padding: 20px 25px;">
                                <span style="display: inline-flex; align-items: center; gap: 5px; padding: 6px 12px; background: <?php echo $statusBg; ?>; color: <?php echo $statusColor; ?>; border-radius: 20px; font-size: 12px; font-weight: 700; border: 1px solid rgba(0,0,0,0.05);">
                                    <span style="width: 6px; height: 6px; background: <?php echo $statusColor; ?>; border-radius: 50%;"></span>
                                    <?php echo $statusText; ?>
                                </span>
                            </td>
                            <td style="padding: 20px 25px; text-align: right;">
                                <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                    <button class="btn-icon-action" onclick='openEditBranchModal(<?php echo json_encode($branch); ?>)' title="Edit Branch" style="background: #eff6ff; color: #2563eb; border: none; padding: 8px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s;">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                    </button>
                                    <button type="button" class="btn-icon-action" title="Delete Branch" onclick="confirmDeleteBranch('?delete_branch=<?php echo $branch['id']; ?>')" style="background: #fff1f2; color: #e11d48; padding: 8px; border-radius: 8px; display: flex; align-items: center; justify-content: center; transition: all 0.2s; border: none; cursor: pointer;">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Branch Modal -->
<div id="branchModal" class="modal-overlay">
    <div class="modal-card" style="background: white; border-radius: 28px; width: 100%; max-width: 650px; box-shadow: 0 50px 100px -20px rgba(0,0,0,0.25); overflow: hidden; transform: translateY(0); transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); max-height: 94vh; display: flex; flex-direction: column;">
        <div class="modal-card-header" style="background: linear-gradient(135deg, #6366f1, #4f46e5); padding: 32px; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 10;">
            <h2 id="modalTitle" style="color: white; margin: 0; font-size: 24px; font-weight: 900; letter-spacing: -0.03em;">Add New Branch</h2>
            <button class="close-modal" onclick="closeBranchModal()" style="background: rgba(255,255,255,0.2); border: none; color: white; width: 36px; height: 36px; border-radius: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(10px); transition: all 0.2s;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div style="overflow-y: auto; flex: 1;">
            <form action="" method="POST" class="modal-form" style="padding: 32px;">
            <input type="hidden" name="action" id="formAction" value="add_branch">
            <input type="hidden" name="branch_id" id="branch_id">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
                <div class="form-group">
                    <label style="font-weight: 700; color: #334155; font-size: 13.5px; margin-bottom: 8px; display: block;">Branch Name</label>
                    <input type="text" name="name" id="branch_name" class="modern-input" placeholder="e.g. Trichowk Branch" required onkeyup="generateSlug(this.value)">
                </div>

                <div class="form-group">
                    <label style="font-weight: 700; color: #334155; font-size: 13.5px; margin-bottom: 8px; display: block;">Slug Identifier</label>
                    <input type="text" name="slug" id="branch_slug" class="modern-input" placeholder="e.g. trichowk" required>
                </div>
            </div>

            <div class="form-group" style="margin-top: 24px;">
                <label style="font-weight: 700; color: #334155; font-size: 13.5px; margin-bottom: 8px; display: block;">Contact Phone</label>
                <input type="text" name="phone" id="branch_phone" class="modern-input" placeholder="e.g. 056-XXXXXX">
            </div>

            <div class="form-group" style="margin-top: 24px;">
                <label style="font-weight: 700; color: #334155; font-size: 13.5px; margin-bottom: 8px; display: block;">Address</label>
                <textarea name="address" id="branch_address" class="modern-input" placeholder="Full physical address" style="height: 100px; resize: none;"></textarea>
            </div>

            <div class="form-group" style="margin-top: 24px;">
                <label style="font-weight: 700; color: #334155; font-size: 13.5px; margin-bottom: 8px; display: block;">Kitchen Coordinates (Paste from Google Maps)</label>
                <div style="display: flex; gap: 10px; align-items: stretch; background: #fff; border: 1.5px solid #e2e8f0; border-radius: 14px; padding: 4px; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.05);" id="coordContainer">
                    <input type="text" id="coordPasteBox" class="modern-input" placeholder="Paste Lat, Lng here..." style="border: none !important; box-shadow: none !important; flex: 1;" onpaste="handleCoordPaste(event)" onkeydown="return (event.ctrlKey || event.metaKey) && (event.key === 'v' || event.key === 'V');" autocomplete="off">
                    <button type="button" onclick="pasteCoordinates()" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; color: #6366f1; padding: 0 16px; font-weight: 700; font-size: 12px; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#eef2ff'" onmouseout="this.style.background='#f8fafc'">
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path><rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect></svg>
                            Paste
                        </div>
                    </button>
                    <button type="button" onclick="clearCoordinates()" style="background: #fdf2f2; border: 1px solid #fee2e2; border-radius: 10px; color: #ef4444; padding: 0 12px; font-weight: 700; font-size: 12px; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fdf2f2'">
                        Clear
                    </button>
                </div>
                <small style="color: #64748b; font-size: 11px; margin-top: 6px; display: block; font-weight: 500;">Note: Manual typing is disabled. Paste coordinates (e.g., 27.70, 84.45) directly into the box.</small>
                
                <!-- Actual Hidden Inputs for Form Submission -->
                <input type="hidden" name="latitude" id="branch_latitude">
                <input type="hidden" name="longitude" id="branch_longitude">
            </div>

            <div id="adminFields" style="margin-top: 32px; background: #f8fafc; padding: 28px; border-radius: 20px; border: 1.5px dashed #e2e8f0;">
                <div style="font-weight: 900; color: #6366f1; font-size: 12px; text-transform: uppercase; letter-spacing: 0.12em; display: flex; align-items: center; gap: 10px; margin-bottom: 24px;">
                    <div style="width: 32px; height: 32px; border-radius: 10px; background: white; color: #6366f1; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8.5" cy="7" r="4"></circle><polyline points="17 11 19 13 23 9"></polyline></svg>
                    </div>
                    <span id="adminFieldsTitle">Initial Admin Credentials</span>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div class="form-group">
                        <label style="font-weight: 800; color: #475569; font-size: 13px; margin-bottom: 8px; display: block; letter-spacing: -0.01em;">Admin Username</label>
                        <input type="text" name="admin_username" id="admin_username" class="modern-input" placeholder="e.g. trichowk_admin" style="background: white !important;">
                    </div>

                    <div class="form-group">
                        <label style="font-weight: 800; color: #475569; font-size: 13px; margin-bottom: 8px; display: block; letter-spacing: -0.01em;">Admin Password</label>
                        <input type="password" name="admin_password" id="admin_password" class="modern-input" placeholder="••••••••" style="background: white !important;">
                    </div>
                </div>
            </div>

            <div class="form-group" style="display: flex; align-items: center; gap: 14px; margin-top: 32px; background: #f1f5f9; padding: 16px; border-radius: 16px;">
                <label class="switch" style="position: relative; display: inline-block; width: 44px; height: 24px;">
                    <input type="checkbox" name="is_active" id="branch_active" checked style="opacity: 0; width: 0; height: 0;">
                    <span class="slider" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .4s; border-radius: 24px;"></span>
                </label>
                <label for="branch_active" style="margin: 0; font-weight: 700; color: #334155; font-size: 14px; cursor: pointer;">Branch is currently active</label>
            </div>

            <div class="modal-footer" id="modalFooterNormal" style="padding-top: 32px; display: flex; gap: 16px;">
                <button type="button" class="btn btn-secondary" onclick="closeBranchModal()" style="flex: 1; padding: 16px; border-radius: 16px; font-weight: 800; background: #f1f5f9; border: none; color: #475569; cursor: pointer; transition: all 0.2s;">Cancel</button>
                <button type="button" id="editUnlockBtn" class="btn btn-info" onclick="unlockBranchModal()" style="flex: 2; padding: 16px; border-radius: 16px; font-weight: 800; background: #eef2ff; border: 1px solid #6366f1; color: #6366f1; cursor: pointer; display: none; transition: all 0.2s;">Edit Details</button>
                <button type="submit" id="saveBranchBtn" class="btn btn-primary" style="flex: 2; padding: 16px; border-radius: 16px; font-weight: 800; background: linear-gradient(135deg, #6366f1, #4f46e5); border: none; color: white; cursor: pointer; box-shadow: 0 10px 20px -5px rgba(79, 70, 229, 0.4); transition: all 0.2s;">Save Branch Settings</button>
            </div>
        </form>
        </div>
    </div>
</div>

    </div>
</div>

<!-- Custom Confirmation Modal -->
<div id="confirmModal" class="modal-overlay" style="z-index: 3000;">
    <div class="modal-card confirm-modal-box">
        <div class="confirm-icon">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
        </div>
        <h3 id="confirmTitle" style="margin-top: 0; margin-bottom: 12px; font-size: 22px; font-weight: 800; color: #0f172a;">Are you sure?</h3>
        <p id="confirmMessage" style="color: #64748b; font-size: 15px; line-height: 1.6; margin-bottom: 32px;">This action cannot be undone. Do you want to proceed?</p>
        <div style="display: flex; gap: 12px; justify-content: center;">
            <button type="button" class="btn btn-secondary" style="flex: 1; padding: 12px; border-radius: 12px; font-weight: 700; cursor: pointer; border: none; background: #f1f5f9; color: #475569;" onclick="closeConfirmModal()">Cancel</button>
            <button type="button" id="confirmOkBtn" class="btn btn-danger" style="flex: 1; padding: 12px; border-radius: 12px; font-weight: 700; cursor: pointer; border: none; background: #ef4444; color: white;">Yes, Delete</button>
        </div>
    </div>
</div>

<script>
function openAddBranchModal() {
    document.getElementById('modalTitle').innerText = 'Add New Branch';
    document.getElementById('formAction').value = 'add_branch';
    document.getElementById('adminFields').style.display = 'block';
    setBranchFieldsDisabled(false);
    document.getElementById('editUnlockBtn').style.display = 'none';
    document.getElementById('saveBranchBtn').style.display = 'block';
    
    document.getElementById('admin_username').required = true;
    document.getElementById('admin_username').value = '';
    document.getElementById('admin_password').required = true;
    document.getElementById('admin_password').placeholder = '••••••••';
    document.getElementById('admin_password').value = '';
    document.getElementById('branch_id').value = '';
    document.getElementById('branch_name').value = '';
    document.getElementById('branch_slug').value = '';
    document.getElementById('branch_address').value = '';
    document.getElementById('branch_phone').value = '';
    document.getElementById('branch_latitude').value = '';
    document.getElementById('branch_longitude').value = '';
    document.getElementById('coordPasteBox').value = '';
    document.getElementById('branch_active').checked = true;
    document.getElementById('branchModal').classList.add('active');
    document.body.classList.add('modal-open');
}

function openEditBranchModal(branch) {
    document.getElementById('modalTitle').innerText = 'Edit Branch Settings';
    document.getElementById('formAction').value = 'edit_branch';
    document.getElementById('adminFields').style.display = 'block';
    
    // Start locked for existing branches
    setBranchFieldsDisabled(true);
    document.getElementById('editUnlockBtn').style.display = 'block';
    document.getElementById('saveBranchBtn').style.display = 'none';

    document.getElementById('admin_username').required = false;
    document.getElementById('admin_username').value = branch.admin_username || '';
    document.getElementById('admin_password').required = false;
    document.getElementById('admin_password').placeholder = 'Leave blank to keep current';
    document.getElementById('admin_password').value = '';
    document.getElementById('branch_id').value = branch.id || '';
    document.getElementById('branch_name').value = branch.name || '';
    document.getElementById('branch_slug').value = branch.slug || '';
    document.getElementById('branch_address').value = branch.address || '';
    document.getElementById('branch_phone').value = branch.phone || '';
    document.getElementById('branch_latitude').value = branch.latitude || '';
    document.getElementById('branch_longitude').value = branch.longitude || '';
    if (branch.latitude && branch.longitude) {
        document.getElementById('coordPasteBox').value = branch.latitude + ', ' + branch.longitude;
    } else {
        document.getElementById('coordPasteBox').value = '';
    }
    document.getElementById('branch_active').checked = branch.is_active == 1;
    document.getElementById('branchModal').classList.add('active');
    document.body.classList.add('modal-open');
}

function unlockBranchModal() {
    setBranchFieldsDisabled(false);
    document.getElementById('editUnlockBtn').style.display = 'none';
    document.getElementById('saveBranchBtn').style.display = 'block';
}

function setBranchFieldsDisabled(disabled) {
    const fields = [
        'branch_name', 'branch_slug', 'branch_phone', 'branch_address',
        'admin_username', 'admin_password', 'branch_active', 'coordPasteBox'
    ];
    fields.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.disabled = disabled;
    });
    // Buttons inside coordinate box
    const coordBtns = document.getElementById('coordContainer').getElementsByTagName('button');
    for (let btn of coordBtns) btn.disabled = disabled;
}

function closeBranchModal() {
    document.getElementById('branchModal').classList.remove('active');
    document.body.classList.remove('modal-open');
    // Ensure unlocked for next opening reset
    setBranchFieldsDisabled(false);
}

function generateSlug(text) {
    if (document.getElementById('formAction').value === 'edit_branch') return;
    const slug = text.toLowerCase()
        .replace(/[^\w\s-]/g, '')
        .replace(/[\s_-]+/g, '-')
        .replace(/^-+|-+$/g, '');
    document.getElementById('branch_slug').value = slug;
}

function handleCoordPaste(e) {
    e.preventDefault();
    const text = (e.clipboardData || window.clipboardData).getData('text');
    parseAndSetCoords(text);
}

async function pasteCoordinates() {
    try {
        const text = await navigator.clipboard.readText();
        parseAndSetCoords(text);
    } catch (err) {
        alert("Please paste manually into the box (Manual typing is disabled)");
    }
}

function parseAndSetCoords(text) {
    const coords = text.replace(/[^\d.,-]/g, '').split(',');
    if (coords.length >= 2) {
        const lat = coords[0].trim();
        const lng = coords[1].trim();
        document.getElementById('branch_latitude').value = lat;
        document.getElementById('branch_longitude').value = lng;
        document.getElementById('coordPasteBox').value = lat + ', ' + lng;
    }
}

function clearCoordinates() {
    document.getElementById('branch_latitude').value = '';
    document.getElementById('branch_longitude').value = '';
    document.getElementById('coordPasteBox').value = '';
}

function confirmDeleteBranch(url) {
    showConfirm(
        'Delete Branch?', 
        'WARNING: Are you sure you want to delete this branch? This action cannot be undone.', 
        'Yes, Delete',
        function() {
            window.location.href = url;
        }
    );
}

// Custom Confirm Logic
let confirmCallback = null;

function showConfirm(title, message, btnText, callback) {
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmMessage').textContent = message;
    const okBtn = document.getElementById('confirmOkBtn');
    okBtn.textContent = btnText;
    document.getElementById('confirmModal').classList.add('active');
    confirmCallback = callback;
}

function closeConfirmModal() {
    document.getElementById('confirmModal').classList.remove('active');
    confirmCallback = null;
}

document.getElementById('confirmOkBtn').onclick = function() {
    if (confirmCallback) confirmCallback();
    closeConfirmModal();
};

// Handle outside clicks
window.onclick = function(event) {
    const branchModal = document.getElementById('branchModal');
    const confirmModal = document.getElementById('confirmModal');
    
    if (event.target === branchModal) {
        closeBranchModal();
    } else if (event.target === confirmModal) {
        closeConfirmModal();
    }
}
</script>

<style>
.rider-admin-container { padding: 40px; max-width: 1300px; margin: 0 auto; }
.admin-actions-flex { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 35px; }

.table-responsive { width: 100%; overflow-x: auto; }
.rider-row:hover { background: #f8fafc; }

.btn-icon-action:hover { transform: translateY(-2px); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }

/* Switch Toggle Styling */
.switch input:checked + .slider { background: linear-gradient(135deg, #10b981, #059669); }
.switch input:checked + .slider:before { transform: translateX(20px); }
.slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }

.modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px); display: none; align-items: center; justify-content: center; z-index: 2000; transition: all 0.3s ease; }
.modal-overlay.active { display: flex; }

.modal-card { transform: scale(0.95); opacity: 0; transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1); }
.modal-overlay.active .modal-card { transform: scale(1); opacity: 1; }

.modern-input { width: 100%; padding: 14px 18px; border-radius: 14px; border: 1.5px solid #e2e8f0; font-size: 15px; font-weight: 700; color: #1e293b; transition: all 0.2s; background: #fff; box-sizing: border-box; font-family: 'Inter', system-ui, -apple-system, sans-serif; }
.modern-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1); background: #fff; }
.modern-input:disabled { background: #fdfdfd !important; color: #64748b; cursor: not-allowed; border-color: #f1f5f9; }
.modern-input::placeholder { color: #94a3b8; font-weight: 500; font-size: 14px; }

label { font-family: 'Inter', system-ui, -apple-system, sans-serif; letter-spacing: -0.01em; }
h2, h3 { font-family: 'Inter', system-ui, -apple-system, sans-serif; letter-spacing: -0.02em; }

@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

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
