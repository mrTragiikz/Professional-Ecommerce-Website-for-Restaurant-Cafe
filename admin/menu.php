<?php
/**
 * Menu Items Management
 */

require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
requireAdminPin();
require_once __DIR__ . '/../config/db.php';

// Handle Form Submissions
if (isset($_SESSION['success_msg'])) {
    $success_msg = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']);
} else {
    $success_msg = '';
}

if (isset($_SESSION['error_msg'])) {
    $error_msg = $_SESSION['error_msg'];
    unset($_SESSION['error_msg']);
} else {
    $error_msg = '';
}

$action = $_POST['action'] ?? '';
$current_cat = $_REQUEST['cat'] ?? 'all';

// Branch Selection Logic
$current_branch_id = getAdminBranchId() ?: 1; // Default to branch 1 if none selected

// Restricted Categories - All categories are now editable and erasable.
$restricted_names = [];

// Add/Edit Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_category') {
    $category_id = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;
    $category_name = trim($_POST['category_name']);
    $display_order = (int) $_POST['display_order'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;



    if (!empty($category_name)) {
        try {
            if ($category_id > 0) {
                // Update
                $stmt = $pdo->prepare("UPDATE menu_categories SET category_name = ?, display_order = ?, is_active = ? WHERE id = ? AND restaurant_id = ?");
                $stmt->execute([$category_name, $display_order, $is_active, $category_id, $current_branch_id]);
                $_SESSION['success_msg'] = "Category updated successfully!";
            } else {
                // Insert
                $category_key = strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $category_name));
                error_log("DEBUG: Category Insert - Current Branch ID: " . var_export($current_branch_id, true));
                // VALIDATION: Ensure the branch ID exists in our branches table to prevent FK violations
                $branchCheck = $pdo->prepare("SELECT id FROM branches WHERE id = ?");
                $branchCheck->execute([$current_branch_id]);
                if (!$branchCheck->fetch()) {
                    throw new Exception("The selected branch (ID: $current_branch_id) does not exist. Please select a valid branch from the dashboard.");
                }

                $stmt = $pdo->prepare("INSERT INTO menu_categories (category_key, category_name, display_order, is_active, restaurant_id) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$category_key, $category_name, $display_order, $is_active, $current_branch_id]);
                $_SESSION['success_msg'] = "Category added successfully!";
            }
            header("Location: menu.php?cat=" . $current_cat);
            exit;
        } catch (Exception $e) {
            $_SESSION['error_msg'] = "Error saving category: " . $e->getMessage();
            header("Location: menu.php?cat=" . $current_cat);
            exit;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_item') {
    $item_id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
    $category_id = (int) $_POST['category_id'];
    $item_name = trim($_POST['item_name']);
    $description = trim($_POST['item_description']);
    $price = (float) $_POST['item_price'];



    if (!empty($item_name) && $category_id > 0) {
        try {
            // Handle Image Upload
            $image_path = null;
            $should_update_image = false;

            if (isset($_FILES['item_image']) && $_FILES['item_image']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['item_image']['tmp_name'];

                // Validate file type using FileInfo (MIME type)
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($fileTmpPath);

                $allowedMimeTypes = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'image/gif' => 'gif'
                ];

                $uploadDir = __DIR__ . '/../assets/menu-items/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                if (array_key_exists($mimeType, $allowedMimeTypes)) {
                    // Use extension based on MIME type to be safe, or keep original if valid
                    $fileExt = $allowedMimeTypes[$mimeType];
                    $newFileName = uniqid('dish_', true) . '.' . $fileExt;
                    $destination = $uploadDir . $newFileName;

                    if (move_uploaded_file($fileTmpPath, $destination)) {
                        chmod($destination, 0644);
                        $image_path = 'assets/menu-items/' . $newFileName;
                        $should_update_image = true;
                    }
                } else {
                    // Invalid MIME type (e.g. video, heic, pdf)
                    // If it's heic, we can give specific error
                    if ($mimeType === 'image/heic' || $mimeType === 'image/heif') {
                        throw new Exception("HEIC images (iPhone) are not supported directly. Please convert to JPG first.");
                    }
                    throw new Exception("Invalid image format: $mimeType. Please upload JPG, PNG, or WEBP.");
                }
            } elseif (isset($_POST['delete_image']) && $_POST['delete_image'] == '1') {
                $image_path = null;
                $should_update_image = true;
            }

            if ($item_id > 0) {
                // Fetch old image to delete if replaced or removed
                $oldImage = '';
                if ($should_update_image) {
                    $oldImgStmt = $pdo->prepare("SELECT image_path FROM menu_items WHERE id = ?");
                    $oldImgStmt->execute([$item_id]);
                    $oldImage = $oldImgStmt->fetchColumn();
                }

                if ($should_update_image) {
                    // Update image path in database
                    $stmt = $pdo->prepare("UPDATE menu_items SET category_id = ?, item_name = ?, item_description = ?, price = ?, image_path = ? WHERE id = ? AND restaurant_id = ?");
                    $stmt->execute([$category_id, $item_name, $description, $price, $image_path, $item_id, $current_branch_id]);

                    // Delete old image file if it exists
                    if (!empty($oldImage) && $oldImage !== $image_path) {
                        $fullOldPath = __DIR__ . '/../' . $oldImage;
                        if (file_exists($fullOldPath)) {
                            unlink($fullOldPath);
                        }
                    }
                } else {
                    $stmt = $pdo->prepare("UPDATE menu_items SET category_id = ?, item_name = ?, item_description = ?, price = ? WHERE id = ? AND restaurant_id = ?");
                    $stmt->execute([$category_id, $item_name, $description, $price, $item_id, $current_branch_id]);
                }

                // Reset old price if it equals or is less than the current price
                // This prevents the "0% OFF" bug where price equals old_price.
                $fixStmt = $pdo->prepare("UPDATE menu_items SET old_price = NULL, offer_end_time = NULL WHERE id = ? AND old_price IS NOT NULL AND price >= old_price");
                $fixStmt->execute([$item_id]);

                $_SESSION['success_msg'] = "Menu item updated successfully!";
            } else {
                // Create new menu item entry
                $stmt = $pdo->prepare("INSERT INTO menu_items (category_id, item_name, item_description, price, image_path, display_order, is_active, restaurant_id, track_stock, stock_count) VALUES (?, ?, ?, ?, ?, 0, 1, ?, 0, 0)");
                $stmt->execute([$category_id, $item_name, $description, $price, $image_path, $current_branch_id]);
                $_SESSION['success_msg'] = "Menu item added successfully!";
            }
            header("Location: menu.php?cat=" . $current_cat);
            exit;
        } catch (Exception $e) {
            $_SESSION['error_msg'] = "Error saving item: " . $e->getMessage();
            header("Location: menu.php?cat=" . $current_cat);
            exit;
        }
    } else {
        $_SESSION['error_msg'] = "Item name and category are required.";
        header("Location: menu.php?cat=" . $current_cat);
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_item') {
    $item_id = (int) $_POST['item_id'];
    if ($item_id > 0) {
        try {
            // Fetch image path to delete file
            $imgStmt = $pdo->prepare("SELECT image_path FROM menu_items WHERE id = ?");
            $imgStmt->execute([$item_id]);
            $imgPath = $imgStmt->fetchColumn();

            $stmt = $pdo->prepare("DELETE FROM menu_items WHERE id = ? AND restaurant_id = ?");
            $stmt->execute([$item_id, $current_branch_id]);

            // Delete physically
            if (!empty($imgPath)) {
                $fullPath = __DIR__ . '/../' . $imgPath;
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }
            }

            $_SESSION['success_msg'] = "Item deleted successfully.";
            header("Location: menu.php?cat=" . $current_cat);
            exit;
        } catch (Exception $e) {
            $_SESSION['error_msg'] = "Error deleting item: " . $e->getMessage();
            header("Location: menu.php?cat=" . $current_cat);
            exit;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_category') {
    $category_id = (int) $_POST['category_id'];
    if ($category_id > 0) {


        try {
            // First check if category has items
            $checkItemsStmt = $pdo->prepare("SELECT COUNT(*) FROM menu_items WHERE category_id = ? AND restaurant_id = ?");
            $checkItemsStmt->execute([$category_id, $current_branch_id]);
            if ($checkItemsStmt->fetchColumn() > 0) {
                $_SESSION['error_msg'] = "Cannot delete category with items. Please delete or move items first.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM menu_categories WHERE id = ? AND restaurant_id = ?");
                $stmt->execute([$category_id, $current_branch_id]);
                $_SESSION['success_msg'] = "Category deleted successfully.";
            }
            header("Location: menu.php?cat=" . $current_cat);
            exit;
        } catch (Exception $e) {
            $_SESSION['error_msg'] = "Error deleting category: " . $e->getMessage();
            header("Location: menu.php?cat=" . $current_cat);
            exit;
        }
    }
}

// Fetch Categories
$categories = [];
try {
    $stmt = $pdo->prepare("SELECT id, category_name, display_order, is_active FROM menu_categories WHERE category_name != 'Combo Offers' AND restaurant_id = ? ORDER BY CASE WHEN category_name LIKE '%Grocery%' OR category_name LIKE '%Groceries%' THEN 1 ELSE 0 END ASC, display_order ASC");
    $stmt->execute([$current_branch_id]);
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Admin categories fetch error: " . $e->getMessage());
}

// Fetch Dishes for Current Branch
$dishes = [];
try {
    $dishesStmt = $pdo->prepare("
        SELECT m.*, c.category_name 
        FROM menu_items m 
        JOIN menu_categories c ON m.category_id = c.id 
        WHERE c.category_name != 'Combo Offers' AND m.restaurant_id = ?
        ORDER BY CASE WHEN c.category_name LIKE '%Grocery%' OR category_name LIKE '%Groceries%' THEN 1 ELSE 0 END ASC, c.display_order ASC, m.display_order ASC
    ");
    $dishesStmt->execute([$current_branch_id]);
    $dishes = $dishesStmt->fetchAll();
} catch (PDOException $e) {
    // Ignore
}

$pageTitle = 'Menu Items';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .menu-management-grid {
        display: grid;
        grid-template-columns: 350px 1fr;
        gap: 30px;
        align-items: start;
    }

    /* Make left column sticky */
    .menu-management-grid>div:first-child {
        position: sticky;
        top: 100px;
        /* Adjust based on header height */
    }

    .panel-card {
        background: white;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        padding: 24px;
        margin-bottom: 24px;
        position: relative;
    }

    .panel-title {
        font-size: 16px;
        font-weight: 700;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .form-group {
        margin-bottom: 16px;
    }

    .form-label {
        display: block;
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 6px;
        color: #475569;
    }

    .form-input,
    .form-select,
    .form-textarea {
        width: 100%;
        padding: 12px 16px;
        border: 1.5px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        font-family: 'Inter', 'Segoe UI', sans-serif;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        background-color: #f8fafc;
        color: #1e293b;
    }

    .form-input:focus,
    .form-select:focus,
    .form-textarea:focus {
        outline: none;
        border-color: #6366f1;
        background-color: #fff;
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.12);
        transform: translateY(-1px);
    }

    .form-textarea {
        height: 100px;
        resize: vertical;
    }

    .btn {
        padding: 12px 20px;
        border-radius: 12px;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        font-size: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .btn:active {
        transform: translateY(0);
    }

    .btn-primary {
        background: #0f172a;
        color: white;
        box-shadow: 0 2px 4px rgba(15, 23, 42, 0.1);
    }

    .btn-primary:hover {
        background: #1e293b;
        box-shadow: 0 8px 16px rgba(15, 23, 42, 0.2);
    }

    .btn-danger {
        background: #fee2e2;
        color: #991b1b;
    }

    .btn-danger:hover {
        background: #fecaca;
        color: #b91c1c;
    }

    .btn-secondary {
        background: #f1f5f9;
        color: #475569;
    }

    .btn-secondary:hover {
        background: #e2e8f0;
        color: #1e293b;
    }

    .category-list {
        display: flex;
        flex-direction: column;
        gap: 8px;
        max-height: calc(100vh - 280px);
        /* Fixed height for sidebar scrolling */
        overflow-y: auto;
        padding-right: 8px;
    }

    /* Custom scrollbar for category list */
    .category-list::-webkit-scrollbar {
        width: 5px;
    }

    .category-list::-webkit-scrollbar-track {
        background: #f1f5f9;
        border-radius: 10px;
    }

    .category-list::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 10px;
    }

    .category-list::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

    .category-item {
        padding: 12px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        transition: all 0.2s;
    }

    .category-item:hover {
        background: #eff6ff;
        border-color: #bfdbfe;
        color: #1e40af;
    }

    .category-item.active {
        background: #f8fafc;
        border-color: #0f172a;
        color: #0f172a;
        border-width: 2px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }

    .category-item.active .cat-name {
        font-weight: 800 !important;
    }

    .category-actions {
        display: flex;
        gap: 6px;
        opacity: 0;
        transition: opacity 0.2s;
    }

    .category-item:hover .category-actions {
        opacity: 1;
    }

    .action-icon-btn {
        padding: 4px;
        border-radius: 4px;
        color: #64748b;
        background: rgba(255, 255, 255, 0.5);
    }

    .action-icon-btn:hover {
        background: white;
        color: #0f172a;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    }

    .dishes-table {
        width: 100%;
        border-collapse: collapse;
    }

    .dishes-table th {
        text-align: left;
        padding: 12px;
        background: #f8fafc;
        font-size: 12px;
        text-transform: uppercase;
        color: #64748b;
        font-weight: 700;
        border-bottom: 1px solid #e2e8f0;
    }

    .dishes-table td {
        padding: 12px;
        border-bottom: 1px solid #f1f5f9;
        font-size: 14px;
        vertical-align: top;
    }

    .price-cell {
        font-family: 'Poppins', sans-serif;
        font-weight: 600;
        color: #059669;
    }

    .badge-active {
        background: #dcfce7;
        color: #166534;
        padding: 4px 8px;
        border-radius: 100px;
        font-size: 11px;
        font-weight: 600;
    }

    .badge-inactive {
        background: #f1f5f9;
        color: #64748b;
        padding: 4px 8px;
        border-radius: 100px;
        font-size: 11px;
        font-weight: 600;
    }

    .alert {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 14px;
    }

    .alert-success {
        background: #dcfce7;
        color: #166534;
        border: 1px solid #bbf7d0;
    }

    .alert-error {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }

    /* Modal Styles */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(15, 23, 42, 0.45);
        z-index: 1000;
        display: none;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        transition: all 0.3s ease;
    }

    .modal-overlay.open {
        display: flex;
    }

    .modal-box {
        background: white;
        border-radius: 24px;
        width: 95%;
        max-width: 550px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        overflow: hidden;
        animation: modalSlideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    @keyframes modalSlideUp {
        from {
            opacity: 0;
            transform: translateY(30px) scale(0.95);
        }

        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .modal-header {
        padding: 24px 32px;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        justify-content: space-between;
        align-items: center;
        background: #fff;
    }

    .modal-title {
        font-size: 20px;
        font-weight: 800;
        color: #0f172a;
        margin: 0;
        letter-spacing: -0.02em;
    }

    .modal-close {
        background: #f1f5f9;
        border: none;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        color: #64748b;
        cursor: pointer;
        transition: all 0.2s;
    }

    .modal-close:hover {
        background: #e2e8f0;
        color: #0f172a;
        transform: rotate(90deg);
    }

    .modal-body {
        padding: 32px;
    }

    .modal-footer {
        padding: 24px 32px;
        border-top: 1px solid #f1f5f9;
        background: #f8fafc;
        display: flex;
        justify-content: flex-end;
        gap: 16px;
    }

    .edit-btn {
        background: none;
        border: none;
        cursor: pointer;
        color: #64748b;
        padding: 6px;
        border-radius: 6px;
        transition: all 0.2s;
    }

    .edit-btn:hover {
        background: #e0e7ff;
        color: #4f46e5;
    }

    .delete-btn {
        background: none;
        border: none;
        cursor: pointer;
        color: #64748b;
        padding: 6px;
        border-radius: 6px;
        transition: all 0.2s;
    }

    .delete-btn:hover {
        background: #fee2e2;
        color: #ef4444;
    }

    body {
        margin: 0;
        padding: 0;
    }

    /* Confirm Modal Specifics */
    .confirm-modal-box {
        max-width: 400px !important;
        text-align: center;
        padding: 40px !important;
        border-radius: 32px !important;
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

<div class="settings-container" style="max-width: 1200px;">
    <div class="settings-header" style="display: flex; justify-content: space-between; align-items: end;">
        <div>
            <h1 class="page-title">Menu Management</h1>
            <p class="page-subtitle">Organize your menu categories and dishes.</p>
        </div>
        <div style="display: flex; gap: 10px;">
            <button class="btn btn-primary" onclick="openAddDishModal()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 5v14M5 12h14" />
                </svg>
                Add New Dish
            </button>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_msg); ?></div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
        <div class="alert alert-error"><?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <div class="menu-management-grid">
        <!-- Left Column: Categories -->
        <div>
            <div class="panel-card">
                <div class="panel-title">
                    <span>Categories</span>
                    <button class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px;"
                        onclick="openCategoryModal()">+ Add</button>
                </div>
                <div class="category-list">
                    <!-- 'All Items' removed as per request -->
                    <?php foreach ($categories as $cat): ?>
                        <div class="category-item" onclick="filterDishes(<?php echo $cat['id']; ?>, this)">
                            <div style="flex: 1;">
                                <div class="cat-name" style="font-weight: 500;">
                                    <?php echo htmlspecialchars($cat['category_name']); ?>
                                </div>
                            </div>
                            <div class="category-actions">
                                <button class="action-icon-btn"
                                    onclick="openCategoryById(<?php echo $cat['id']; ?>); event.stopPropagation();">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                    </svg>
                                </button>
                                <button class="action-icon-btn"
                                    onclick="confirmDeleteCategory(<?php echo $cat['id']; ?>); event.stopPropagation();"
                                    style="color: #ef4444;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                        stroke-width="2">
                                        <polyline points="3 6 5 6 21 6"></polyline>
                                        <path
                                            d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                        </path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Right Column: Dishes -->
        <div>
            <div class="panel-card">
                <div class="panel-title">
                    <span id="dishesListTitle">All Menu Items</span>
                    <span
                        style="font-size: 12px; font-weight: 400; background: #f1f5f9; padding: 2px 8px; border-radius: 12px; color: #64748b;">
                        <?php echo count($dishes); ?> Dishes
                    </span>
                </div>

                <?php if (count($dishes) > 0): ?>
                    <div style="overflow-x: auto;">
                        <table class="dishes-table">
                            <thead>
                                <tr>
                                    <th width="35%">Item Details</th>
                                    <th width="15%">Category</th>
                                    <th width="12%">Standard Rate</th>
                                    <th width="12%">Selling Price</th>
                                    <th width="10%">Status</th>
                                    <th width="16%" style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dishes as $dish): ?>
                                    <tr class="dish-row category-<?php echo $dish['category_id']; ?>">
                                        <td>
                                            <div style="font-weight: 600; color: #0f172a; margin-bottom: 2px;">
                                                <?php echo htmlspecialchars($dish['item_name']); ?>
                                            </div>
                                            <?php if (!empty($dish['image_path'])):
                                                $imgSrc = '../' . ltrim(str_replace('\\', '/', $dish['image_path']), '/');
                                                ?>
                                                <div style="margin: 4px 0;">
                                                    <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="Dish"
                                                        style="width: 40px; height: 40px; object-fit: cover; border-radius: 4px;"
                                                        loading="lazy" onerror="this.style.opacity='0.5'; this.alt='Img Error';">
                                                </div>
                                            <?php endif; ?>

                                        </td>
                                        <td>
                                            <span
                                                style="font-size: 11px; font-weight: 600; background: #f8fafc; border: 1px solid #e2e8f0; padding: 4px 8px; border-radius: 6px; color: #475569;">
                                                <?php echo htmlspecialchars($dish['category_name']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span style="font-weight: 600; color: #64748b;">
                                                Rs.
                                                <?php echo number_format(($dish['old_price'] > 0 && $dish['old_price'] > $dish['price']) ? $dish['old_price'] : $dish['price']); ?>
                                            </span>
                                        </td>
                                        <td class="price-cell">
                                            <?php if (!empty($dish['old_price']) && $dish['old_price'] > $dish['price']): ?>
                                                <span style="color: #E31837; font-weight: 700;">
                                                    Rs. <?php echo number_format($dish['price']); ?>
                                                </span>
                                                <?php if (!empty($dish['offer_end_time']) && strtotime($dish['offer_end_time']) > time()): ?>
                                                    <div class="admin-timer"
                                                        data-end="<?php echo strtotime($dish['offer_end_time']); ?>"
                                                        style="font-size: 10px; color: #ea580c; font-weight: 600; margin-top: 2px; white-space: nowrap;">
                                                        Computing...
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: #059669; font-weight: 600;">
                                                    Rs. <?php echo number_format($dish['price']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($dish['is_active']): ?>
                                                <span class="badge-active">Active</span>
                                            <?php else: ?>
                                                <span class="badge-inactive">Hidden</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                                <button class="edit-btn" onclick='openEditDishModal(<?php echo json_encode($dish); ?>)'>
                                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                                                        stroke="currentColor" stroke-width="2">
                                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                                    </svg>
                                                </button>
                                                <button class="delete-btn"
                                                    onclick="confirmDeleteDish(<?php echo $dish['id']; ?>, '<?php echo addslashes($dish['item_name']); ?>')">
                                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                                                        stroke="currentColor" stroke-width="2">
                                                        <polyline points="3 6 5 6 21 6"></polyline>
                                                        <path
                                                            d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2">
                                                        </path>
                                                        <line x1="10" y1="11" x2="10" y2="17"></line>
                                                        <line x1="14" y1="11" x2="14" y2="17"></line>
                                                    </svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #94a3b8;">
                        No dishes found. Click "Add New Dish" to get started.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Category Modal -->
<div id="categoryModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3 class="modal-title" id="categoryModalTitle">Add New Category</h3>
            <button class="modal-close" onclick="closeModal('categoryModal')">&times;</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="action" value="save_category">
                <input type="hidden" name="category_id" id="cat_id">
                <div class="form-group">
                    <label class="form-label">Category Name</label>
                    <input type="text" name="category_name" id="cat_name" class="form-input" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Display Order</label>
                    <input type="number" name="display_order" id="cat_order" class="form-input" value="0">
                </div>
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 14px;">
                        <input type="checkbox" name="is_active" id="cat_active" checked> Category is Active
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('categoryModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Category</button>
            </div>
        </form>
    </div>
</div>

<!-- Dish Modal -->
<div id="dishModal" class="modal-overlay">
    <div class="modal-box" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title" id="dishModalTitle">Add New Dish</h3>
            <button class="modal-close" onclick="closeModal('dishModal')">&times;</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="modal-body">
                <input type="hidden" name="action" value="save_item">
                <input type="hidden" name="item_id" id="dish_id">
                <input type="hidden" name="delete_image" id="delete_image_input" value="0">

                <div class="form-group">
                    <label class="form-label">Select Category</label>
                    <select name="category_id" id="dish_category" class="form-select" required>
                        <option value="">Choose category...</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label class="form-label">Item Name</label>
                        <input type="text" name="item_name" id="dish_name" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Price</label>
                        <input type="number" step="0.01" name="item_price" id="dish_price" class="form-input" required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Dish Image</label>
                    <div id="image_preview_container"
                        style="display:none; margin-bottom:10px; padding:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; display:flex; align-items:center; gap:12px;">
                        <img id="current_dish_image" src="" alt="Preview"
                            style="width:60px; height:60px; object-fit:cover; border-radius:6px; border:1px solid #cbd5e1;">
                        <div style="flex:1;">
                            <div style="font-size:12px; color:#64748b; font-weight:600;">Current Image</div>
                            <button type="button" id="remove_image_btn" class="btn btn-danger"
                                style="padding:4px 8px; font-size:11px; margin-top:4px;">Remove Image</button>
                        </div>
                    </div>
                    <input type="file" name="item_image" id="dish_image" class="form-input"
                        accept="image/jpeg,image/png,image/webp,image/gif">
                </div>

                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="item_description" id="dish_description" class="form-textarea"></textarea>
                </div>


            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('dishModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Dish</button>
            </div>
        </form>
    </div>
</div>

<!-- Forms for actions -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" id="deleteAction" value="">
    <input type="hidden" name="item_id" id="deleteItemId" value="">
    <input type="hidden" name="category_id" id="deleteCategoryId" value="">
    <input type="hidden" name="cat" id="deleteCatParam" value="<?php echo $current_cat; ?>">
</form>

<input type="hidden" class="current-cat-input" value="<?php echo htmlspecialchars($current_cat); ?>">

<script>
    function openCategoryById(id) {
        const cat = <?php echo json_encode($categories); ?>.find(c => c.id == id);
        if (cat) openCategoryModal(cat);
    }

    function filterDishes(categoryId, element) {
        // Update URL without reload
        const url = new URL(window.location);
        url.searchParams.set('cat', categoryId);
        window.history.pushState({}, '', url);

        // Update technical storage for form submissions
        document.querySelectorAll('.current-cat-input').forEach(input => input.value = categoryId);
        const delCatInput = document.getElementById('deleteCatParam');
        if (delCatInput) delCatInput.value = categoryId;

        // Update active class on category items
        document.querySelectorAll('.category-item').forEach(el => el.classList.remove('active'));
        if (element) element.classList.add('active');

        // Filter table rows
        const rows = document.querySelectorAll('.dish-row');
        rows.forEach(row => {
            if (categoryId === 'all' || row.classList.contains('category-' + categoryId)) {
                row.style.display = 'table-row';
            } else {
                row.style.display = 'none';
            }
        });

        // Update title
        const titleEl = document.getElementById('dishesListTitle');
        if (categoryId === 'all') {
            titleEl.textContent = 'All Menu Items';
        } else {
            const nameEl = element.querySelector('.cat-name');
            const catName = nameEl ? nameEl.textContent.trim() : 'Category';
            titleEl.textContent = catName + ' Menu Items';
        }

        // Auto-scroll to top of the items list
        window.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    }

    // Modal Helpers
    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('open');
        document.documentElement.classList.remove('modal-open');
        document.body.classList.remove('modal-open');
    }

    // Category Modal Logic
    function openCategoryModal(category = null) {
        const modal = document.getElementById('categoryModal');
        const title = document.getElementById('categoryModalTitle');

        if (category) {
            title.textContent = 'Edit Category';
            document.getElementById('cat_id').value = category.id;
            document.getElementById('cat_name').value = category.category_name;
            document.getElementById('cat_order').value = category.display_order;
            document.getElementById('cat_active').checked = category.is_active == 1;
        } else {
            title.textContent = 'Add New Category';
            document.getElementById('cat_id').value = '';
            document.getElementById('cat_name').value = '';
            document.getElementById('cat_order').value = '0';
            document.getElementById('cat_active').checked = true;
        }

        modal.classList.add('open');
        document.documentElement.classList.add('modal-open');
        document.body.classList.add('modal-open');
    }

    function confirmDeleteCategory(id) {
        showConfirm(
            'Delete Category?', 
            'Are you sure you want to delete this category? It must be empty first.', 
            'Yes, Delete',
            function() {
                document.getElementById('deleteAction').value = 'delete_category';
                document.getElementById('deleteCategoryId').value = id;
                document.getElementById('deleteForm').submit();
            }
        );
    }

    // Dish Modal Logic
    function openAddDishModal() {
        openEditDishModal(null);
    }

    function openEditDishModal(dish = null) {
        const modal = document.getElementById('dishModal');
        const title = document.getElementById('dishModalTitle');
        const catSelect = document.getElementById('dish_category');

        // Reset category field state
        catSelect.disabled = false;
        catSelect.style.backgroundColor = '';
        catSelect.style.cursor = '';
        const existingHidden = document.getElementById('hidden_dish_category');
        if (existingHidden) existingHidden.remove();

        if (dish) {
            title.textContent = 'Edit Dish';
            document.getElementById('dish_id').value = dish.id;
            catSelect.value = dish.category_id;

            // Lock category for existing dishes
            catSelect.disabled = true;
            catSelect.style.backgroundColor = '#f8fafc';
            catSelect.style.cursor = 'not-allowed';

            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'category_id';
            hiddenInput.id = 'hidden_dish_category';
            hiddenInput.value = dish.category_id;
            catSelect.parentNode.appendChild(hiddenInput);
            document.getElementById('dish_name').value = dish.item_name;
            document.getElementById('dish_description').value = dish.item_description;
            // Handle float precision for price
            const priceVal = parseFloat(dish.price);
            document.getElementById('dish_price').value = !isNaN(priceVal) ? priceVal.toFixed(2) : '0.00';



            // Handle Image Preview
            const imgPreview = document.getElementById('current_dish_image');
            const imgContainer = document.getElementById('image_preview_container');
            const delInput = document.getElementById('delete_image_input');
            const removeBtn = document.getElementById('remove_image_btn');

            // Reset delete state
            if (delInput) {
                delInput.value = '0';
                imgPreview.style.opacity = '1';
            }

            if (removeBtn) {
                removeBtn.textContent = 'Remove Image';

                // Remove old event listener by replacing the node
                const newBtn = removeBtn.cloneNode(true);
                removeBtn.parentNode.replaceChild(newBtn, removeBtn);

                newBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const currentVal = document.getElementById('delete_image_input').value;
                    if (currentVal === '0') {
                        // Mark for deletion
                        document.getElementById('delete_image_input').value = '1';
                        this.textContent = 'Undo Remove';
                        this.classList.remove('btn-danger');
                        this.classList.add('btn-secondary');
                        document.getElementById('current_dish_image').style.opacity = '0.3';
                    } else {
                        // Undo deletion
                        document.getElementById('delete_image_input').value = '0';
                        this.textContent = 'Remove Image';
                        this.classList.remove('btn-secondary');
                        this.classList.add('btn-danger');
                        document.getElementById('current_dish_image').style.opacity = '1';
                    }
                });
            }

            if (dish.image_path) {
                imgPreview.src = '../' + dish.image_path;
                imgContainer.style.display = 'block';
            } else {
                imgContainer.style.display = 'none';
            }
        } else {
            title.textContent = 'Add New Dish';
            document.getElementById('dish_id').value = '';

            // Reset image
            document.getElementById('dish_image').value = '';
            document.getElementById('image_preview_container').style.display = 'none';
            if (document.getElementById('delete_image_input')) {
                document.getElementById('delete_image_input').value = '0';
            }

            // Lock category if we are filtering by one
            const currentCat = document.querySelector('.current-cat-input').value;
            if (currentCat && currentCat !== 'all') {
                catSelect.value = currentCat;
                catSelect.disabled = true;
                catSelect.style.backgroundColor = '#f8fafc';
                catSelect.style.cursor = 'not-allowed';

                // Add hidden input to carry the value since disabled fields aren't submitted
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'category_id';
                hiddenInput.id = 'hidden_dish_category';
                hiddenInput.value = currentCat;
                catSelect.parentNode.appendChild(hiddenInput);
            } else {
                catSelect.value = '';
            }

            document.getElementById('dish_name').value = '';
            document.getElementById('dish_description').value = '';
            document.getElementById('dish_price').value = '';


        }

        modal.classList.add('open');
        document.documentElement.classList.add('modal-open');
        document.body.classList.add('modal-open');
    }


    // Auto-filter on load
    window.addEventListener('load', () => {
        const urlParams = new URLSearchParams(window.location.search);
        let catId = urlParams.get('cat');

        let targetEl = null;

        // If specific category requested, try to find it
        if (catId && catId !== 'all') {
            targetEl = document.querySelector(`.category-item[onclick*="filterDishes(${catId},"]`);
        }

        // Default to first category if 'all' or not found
        if (!targetEl) {
            targetEl = document.querySelector('.category-item');
            if (targetEl) {
                // Extract ID from onclick attribute
                const match = targetEl.getAttribute('onclick').match(/filterDishes\(([^,]+),/);
                if (match) {
                    catId = match[1];
                }
            }
        }

        if (targetEl && catId) {
            try { filterDishes(catId, targetEl); } catch (e) { console.error(e); }
        }
    });

    function confirmDeleteDish(id, name) {
        showConfirm(
            'Delete Dish?', 
            'Are you sure you want to delete "' + name + '"?', 
            'Yes, Delete',
            function() {
                document.getElementById('deleteAction').value = 'delete_item';
                document.getElementById('deleteItemId').value = id;
                document.getElementById('deleteForm').submit();
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
        document.getElementById('confirmModal').classList.add('open');
        confirmCallback = callback;
    }

    function closeConfirmModal() {
        document.getElementById('confirmModal').classList.remove('open');
        confirmCallback = null;
    }

    document.getElementById('confirmOkBtn').onclick = function() {
        if (confirmCallback) confirmCallback();
        closeConfirmModal();
    };

    // Close modals on outside click
    window.onclick = function (event) {
        if (event.target.classList.contains('modal-overlay')) {
            event.target.classList.remove('open');
            document.documentElement.classList.remove('modal-open');
            document.body.classList.remove('modal-open');
        }
    }

    // Prevent double submission
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function (e) {
            const btn = this.querySelector('button[type="submit"]');

            // SECURITY PROTECTION: Frontend validation before data flows to SQL
            const priceField = this.querySelector('input[name="item_price"]');
            if (priceField && parseFloat(priceField.value) < 0) {
                alert('Price cannot be negative.');
                e.preventDefault();
                return;
            }

            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span style="display:inline-block;animation:spin 1s linear infinite;">↻</span> Processing...';
            }
        });
    });



    // Image preview handler
    const dishImageInput = document.getElementById('dish_image');
    if (dishImageInput) {
        dishImageInput.addEventListener('change', function () {
            if (this.files && this.files[0]) {
                const file = this.files[0];
                const reader = new FileReader();
                reader.onload = function (e) {
                    const imgContainer = document.getElementById('image_preview_container');
                    const imgPreview = document.getElementById('current_dish_image');
                    if (imgPreview && imgContainer) {
                        imgPreview.src = e.target.result;
                        imgPreview.style.opacity = '1';
                        imgContainer.style.display = 'block';
                    }
                    // Reset removal state if new image selected
                    const delInput = document.getElementById('delete_image_input');
                    const removeBtn = document.getElementById('remove_image_btn');
                    if (delInput) delInput.value = '0';
                    if (removeBtn) {
                        removeBtn.textContent = 'Remove Image';
                        removeBtn.classList.remove('btn-secondary');
                        removeBtn.classList.add('btn-danger');
                    }
                }
                reader.readAsDataURL(file);
            }
        });
    }

    // Add spin animation
    const style = document.createElement('style');
    style.innerHTML = '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
    document.head.appendChild(style);

    // Short Simple Timer for Admin List
    setInterval(function () {
        document.querySelectorAll('.admin-timer').forEach(function (el) {
            const end = parseInt(el.dataset.end);
            const now = Math.floor(Date.now() / 1000);
            const diff = end - now;

            if (diff <= 0) {
                el.innerText = 'Expired';
                el.style.color = '#94a3b8';
                return;
            }

            const h = Math.floor(diff / 3600);
            const m = Math.floor((diff % 3600) / 60);

            let text = '';
            if (h > 0) text += h + 'h ';
            if (h > 0 || m > 0) text += m + 'm';
            if (h == 0 && m == 0) text = (diff % 60) + 's';

            el.innerText = '⏱ ' + text;
        });
    }, 1000);
</script>

<!-- Custom Confirmation Modal -->
<div id="confirmModal" class="modal-overlay" style="z-index: 2000;">
    <div class="modal-box confirm-modal-box">
        <div class="confirm-icon">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
        </div>
        <h3 id="confirmTitle" style="margin-top: 0; margin-bottom: 12px; font-size: 22px; font-weight: 800; color: #0f172a;">Are you sure?</h3>
        <p id="confirmMessage" style="color: #64748b; font-size: 15px; line-height: 1.6; margin-bottom: 32px;">This action cannot be undone. Do you want to proceed?</p>
        <div style="display: flex; gap: 12px; justify-content: center;">
            <button type="button" class="btn" style="background: #f1f5f9; color: #475569; flex: 1;" onclick="closeConfirmModal()">Cancel</button>
            <button type="button" id="confirmOkBtn" class="btn btn-danger" style="flex: 1;">Yes, Delete</button>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>