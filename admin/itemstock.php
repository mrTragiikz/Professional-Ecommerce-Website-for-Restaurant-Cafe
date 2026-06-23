<?php
ob_start();
/**
 * Item Stock Management
 */

require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
requireAdminPin();
require_once __DIR__ . '/../config/db.php';

// Ensure columns exist.
function ensureMenuCategoriesTrackStock(PDO $pdo): void
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM menu_categories LIKE 'track_stock'");
        $exists = $stmt->fetch();

        if (!$exists) {
            $pdo->exec("ALTER TABLE menu_categories ADD COLUMN track_stock TINYINT(1) DEFAULT 0");
        }

        // Sync items tracking status with category settings.
        $pdo->exec("
            UPDATE menu_items AS mi
            JOIN menu_categories AS mc
              ON mi.category_id = mc.id
             AND mi.restaurant_id = mc.restaurant_id
            SET mi.track_stock = 0
            WHERE mc.track_stock = 0 AND mi.track_stock = 1
        ");
    } catch (Throwable $e) {
        error_log('Item Stock - track_stock ensure error: ' . $e->getMessage());
    }
}

ensureMenuCategoriesTrackStock($pdo);

// Handle AJAX status updates
if (isset($_POST['action'])) {
    // Clear output for AJAX request.
    error_reporting(0);
    ini_set('display_errors', 0);
    if (ob_get_length()) ob_clean();
    
    header('Content-Type: application/json');
    $current_branch_id = getAdminBranchId() ?: 1; // Default to branch 1 if none selected

    // Get the current branch ID.
    if (isset($_POST['restaurant_id'])) {
        $current_branch_id = (int) $_POST['restaurant_id'];
    } elseif (isset($_POST['branch_id'])) {
        $current_branch_id = (int) $_POST['branch_id'];
    }

    try {
        if ($_POST['action'] === 'update_count') {
            $item_id = (int) ($_POST['item_id'] ?? 0);
            $count = (int) $_POST['count'];
            // Update stock and enable tracking.
            $stmt = $pdo->prepare("UPDATE menu_items SET stock_count = ?, track_stock = 1 WHERE id = ? AND restaurant_id = ?");
            $stmt->execute([$count, $item_id, $current_branch_id]);
            echo json_encode(['success' => true]);
            exit;
        }

        if ($_POST['action'] === 'add_item') {
            $name = trim($_POST['item_name']);
            $price = (float) $_POST['price'];
            $category_id = (int) $_POST['category_id'];
            $stock = (int) $_POST['stock'];

            $stmt = $pdo->prepare("INSERT INTO menu_items (item_name, price, category_id, restaurant_id, stock_count, track_stock, is_active) VALUES (?, ?, ?, ?, ?, 1, 1)");
            $stmt->execute([$name, $price, $category_id, $current_branch_id, $stock]);
            
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            exit;
        }

        if ($_POST['action'] === 'toggle_cat_stock') {
            $cat_id = (int) $_POST['cat_id'];
            $status = (int) $_POST['status'];
            $target_branch_id = (int) ($_POST['restaurant_id'] ?? getAdminBranchId());

            // Verify category belongs to branch.
            $stmt = $pdo->prepare("SELECT id, category_name FROM menu_categories WHERE id = ? AND restaurant_id = ?");
            $stmt->execute([$cat_id, $target_branch_id]);
            $local_cat = $stmt->fetch();

            if ($local_cat) {
                // Local category exists: simply toggle tracking
                $pdo->prepare("UPDATE menu_categories SET track_stock = ? WHERE id = ?")->execute([$status, $cat_id]);
                $local_id = $cat_id;
            } else {
                // Create local category if it's a reference from another branch.
                $stmt = $pdo->prepare("SELECT category_name FROM menu_categories WHERE id = ?");
                $stmt->execute([$cat_id]);
                $cat_name = $stmt->fetchColumn();

                if (!$cat_name) {
                    echo json_encode(['success' => false, 'error' => 'Category not found']);
                    exit;
                }

                $stmt = $pdo->prepare("SELECT id FROM menu_categories WHERE category_name = ? AND restaurant_id = ?");
                $stmt->execute([$cat_name, $target_branch_id]);
                $local_id = $stmt->fetchColumn();

                if (!$local_id) {
                    $stmt = $pdo->prepare("INSERT INTO menu_categories (category_name, restaurant_id, track_stock, display_order) VALUES (?, ?, ?, 0)");
                    $stmt->execute([$cat_name, $target_branch_id, $status]);
                    $local_id = $pdo->lastInsertId();
                } else {
                    $pdo->prepare("UPDATE menu_categories SET track_stock = ? WHERE id = ?")->execute([$status, $local_id]);
                }

                // Sync category IDs for branch items.
                $newTrackStock = $status == 0 ? 0 : 'track_stock'; // Preserve existing tracking status if turning ON, explicitly disable if turning OFF
                $pdo->prepare("UPDATE menu_items SET category_id = ?, track_stock = $newTrackStock WHERE category_id = ? AND restaurant_id = ?")
                    ->execute([$local_id, $cat_id, $target_branch_id]);
            }

            // Disable tracking for all items in this category.
            if ($status == 0) {
                $pdo->prepare("UPDATE menu_items SET track_stock = 0 WHERE category_id = ? AND restaurant_id = ?")
                    ->execute([$local_id, $target_branch_id]);
            }
            
            echo json_encode(['success' => true, 'local_id' => $local_id]);
            exit;
        }
    } catch (PDOException $e) {
        if (ob_get_length()) ob_clean();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Branch Selection Logic - Handle Super Admin context correctly
$current_branch_id = getAdminBranchId();
if (!$current_branch_id) {
    try {
        $stmt = $pdo->query("SELECT id FROM branches LIMIT 1");
        $fallback = $stmt->fetchColumn();
        $current_branch_id = $fallback ?: 1;
    } catch (Exception $e) {
        $current_branch_id = 1;
    }
}

// Fetch Categories that have stock tracking enabled for CURRENT branch
$categories = [];
try {
    // Fetch active tracked categories, excluding 'Combo Offers' from the dashboard scope
    $stmt = $pdo->prepare("SELECT id, category_name FROM menu_categories WHERE track_stock = 1 AND category_name != 'Combo Offers' AND restaurant_id = ? ORDER BY display_order ASC");
    $stmt->execute([$current_branch_id]);
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Stock dashboard - Categories error: " . $e->getMessage());
}

// Fetch ALL available categories for the management modal
$all_categories = [];
try {
    // Stage 1: Fetch branch specific categories, excluding 'Combo Offers'
    $stmt = $pdo->prepare("SELECT id, category_name, track_stock, restaurant_id FROM menu_categories WHERE restaurant_id = ? AND category_name != 'Combo Offers' ORDER BY category_name ASC");
    $stmt->execute([$current_branch_id]);
    $all_categories = $stmt->fetchAll();
    
    // Stage 2: Fallback Logic - Show categories from other branches (excluding Combo Offers)
    if (empty($all_categories)) {
        $stmt = $pdo->prepare("SELECT id, category_name, track_stock, restaurant_id FROM menu_categories WHERE category_name != 'Combo Offers' ORDER BY category_name ASC LIMIT 50");
        $stmt->execute();
        $all_categories = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    error_log("Stock dashboard - All Categories error: " . $e->getMessage());
}

$dishes = [];
try {
    $dishesStmt = $pdo->prepare("
        SELECT m.id, m.item_name, m.price, m.is_active, m.category_id, m.stock_count, m.track_stock, c.category_name 
        FROM menu_items m 
        JOIN menu_categories c ON m.category_id = c.id 
        WHERE c.track_stock = 1 AND m.restaurant_id = ?
        ORDER BY c.display_order ASC, m.display_order ASC
    ");
    $dishesStmt->execute([$current_branch_id]);
    $dishes = $dishesStmt->fetchAll();

    // Auto-enable tracking for ALL items shown on this page.
    // If an item appears here, it means its category is tracked, so the item should be tracked too.
    if (!empty($dishes)) {
        $ids = array_column($dishes, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE menu_items SET track_stock = 1 WHERE id IN ($placeholders) AND restaurant_id = ?")
            ->execute(array_merge($ids, [$current_branch_id]));
        // Update the local array so the badge shows correctly without a reload.
        foreach ($dishes as &$d) { $d['track_stock'] = 1; }
        unset($d);
    }
} catch (PDOException $e) {
}

$pageTitle = 'Item Stock';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* Professional Dashboard Theme */
    :root {
        --primary-navy: #0f172a;
        --accent-green: #10b981;
        --border-slate: #e2e8f0;
        --text-slate: #64748b;
        --bg-slate: #f8fafc;
        --radius-std: 10px;
        --radius-sm: 6px;
    }

    .stock-grid {
        display: grid;
        grid-template-columns: 280px 1fr;
        gap: 32px;
        align-items: start;
    }

    .panel-card {
        background: white;
        border-radius: var(--radius-std);
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05), 0 1px 2px rgba(0, 0, 0, 0.03);
        border: 1px solid var(--border-slate);
        padding: 24px;
        margin-bottom: 24px;
    }

    .panel-title {
        font-size: 14px;
        font-weight: 700;
        margin-bottom: 20px;
        color: var(--primary-navy);
        text-transform: uppercase;
        letter-spacing: 0.03em;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    /* Professional Sidebar */
    .category-nav {
        display: flex;
        flex-direction: column;
        gap: 4px;
        max-height: calc(100vh - 250px);
        overflow-y: auto;
    }

    .category-link {
        padding: 12px 16px;
        border-radius: var(--radius-sm);
        color: var(--text-slate);
        text-decoration: none;
        font-size: 14px;
        font-weight: 600;
        transition: all 0.15s ease;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 12px;
        border-left: 3px solid transparent;
    }

    .category-link:hover {
        background: var(--bg-slate);
        color: var(--primary-navy);
    }

    .category-link.active {
        background: #f1f5f9;
        color: var(--primary-navy);
        border-left-color: var(--primary-navy);
    }

    /* Table & Search */
    .stock-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }

    .stock-table th {
        background: var(--bg-slate);
        padding: 16px;
        font-size: 11px;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: var(--text-slate);
        border-bottom: 1px solid var(--border-slate);
        font-weight: 700;
        text-align: left;
    }

    .stock-table td {
        padding: 16px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }

    .item-info h4 {
        font-size: 15px;
        font-weight: 700;
        color: var(--primary-navy);
        margin: 0 0 4px 0;
    }

    .item-info p {
        font-size: 13px;
        color: var(--text-slate);
        margin: 0;
    }

    .tracking-badge {
        font-size: 10px;
        background: #fee2e2;
        color: #dc2626;
        padding: 2px 8px;
        border-radius: 4px;
        font-weight: 700;
        display: inline-block;
        margin-top: 5px;
        letter-spacing: 0.03em;
    }

    /* Clean Quantity Controls */
    .qty-control {
        display: inline-flex;
        align-items: center;
        background: #fff;
        border: 1px solid var(--border-slate);
        border-radius: var(--radius-sm);
        padding: 4px;
        gap: 2px;
        height: 40px;
    }

    .qty-btn {
        width: 32px;
        height: 32px;
        border-radius: 4px;
        border: none;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 18px;
        font-weight: 700;
        transition: all 0.1s ease;
    }

    .qty-btn.minus { background: #fee2e2; color: #dc2626; }
    .qty-btn.plus { background: #d1fae5; color: #059669; }
    .qty-btn:hover { filter: brightness(0.95); }
    .qty-btn:active { transform: scale(0.95); }

    .qty-input {
        width: 50px;
        border: none;
        background: transparent;
        text-align: center;
        font-weight: 700;
        font-size: 14px;
        color: var(--primary-navy);
        outline: none;
    }

    .confirm-qty-btn {
        display: none;
        background: var(--primary-navy);
        color: white;
        border: none;
        border-radius: var(--radius-sm);
        padding: 0 16px;
        height: 40px;
        margin-left: 8px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .confirm-qty-btn.visible { display: inline-flex; align-items: center; }

    /* Professional Modal */
    .modal-overlay {
        position: fixed;
        top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(15, 23, 42, 0.7);
        backdrop-filter: blur(4px);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 2000;
    }

    .modal-content {
        background: white;
        border-radius: 12px;
        width: 100%;
        max-width: 650px;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        overflow: hidden;
    }

    .modal-header {
        background: var(--primary-navy);
        padding: 24px 32px;
        color: white;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .modal-body {
        padding: 32px;
        max-height: 450px;
        overflow-y: auto;
    }

    .cat-toggle-card {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px;
        border: 1px solid var(--border-slate);
        border-radius: 8px;
        margin-bottom: 8px;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .cat-toggle-card:hover { border-color: var(--text-slate); background: var(--bg-slate); }
    .cat-toggle-card.active { border-color: var(--accent-green); background: #f0fdf4; }

    .source-pill {
        font-size: 10px;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 100px;
        text-transform: uppercase;
        margin-top: 4px;
        display: inline-block;
    }
    .pill-local { background: #dcfce7; color: #166534; }
    .pill-global { background: #f1f5f9; color: #475569; }

    .custom-checkbox {
        width: 24px;
        height: 24px;
        border: 2px solid var(--border-slate);
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: white;
    }
    .cat-toggle-card.active .custom-checkbox { background: var(--accent-green); border-color: var(--accent-green); }

    .modal-cat-search {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid var(--border-slate);
        border-radius: 8px;
        font-size: 14px;
        margin-bottom: 20px;
        outline: none;
    }
    .modal-cat-search:focus { border-color: var(--primary-navy); }

</style>

<div class="settings-container" style="max-width: 1600px; margin: 0 auto; padding: 40px 20px;">
    <div class="settings-header" style="margin-bottom: 40px;">
        <h1 class="page-title" style="font-size: 32px; font-weight: 900; letter-spacing: -0.04em;">Stock Management</h1>
        <p class="page-subtitle" style="font-size: 16px; color: #64748b; font-weight: 500;">Update your food item quantities and tracking.</p>
    </div>

    <div class="stock-grid">
        <!-- Category Sidebar -->
        <div class="panel-card" style="position: sticky; top: 100px;">
            <div class="panel-title" style="margin-bottom: 24px;">
                <span style="font-size: 14px; text-transform: uppercase; color: #64748b; letter-spacing: 0.05em;">Categorization</span>
            </div>
            <button onclick="openManageCatsModal()" style="width: 100%; border: 2px dashed #e2e8f0; border-radius: 12px; background: #f8fafc; color: #4f46e5; padding: 16px; font-weight: 800; font-size: 13px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 24px; transition: all 0.2s;" onmouseover="this.style.background='#f1f5f9'; this.style.borderColor='#cbd5e1'" onmouseout="this.style.background='#f8fafc'; this.style.borderColor='#e2e8f0'">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                Show / Hide Categories
            </button>
            <div class="category-nav">
                <div class="category-link active" onclick="filterStock('all', this)">
                    All Items
                </div>
                <?php foreach ($categories as $cat): ?>
                    <div class="category-link" onclick="filterStock(<?php echo $cat['id']; ?>, this)">
                        <?php echo htmlspecialchars($cat['category_name']); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Item Data List -->
        <div>
            <div class="panel-card">
                <div style="display: flex; gap: 12px; align-items: center; margin-bottom: 24px;">
                    <div class="search-bar" style="flex: 1; margin-bottom: 0;">
                        <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                        <input type="text" id="stockSearch" class="search-input" placeholder="Search dish name..."
                            onkeyup="searchItems()">
                    </div>
                    <button onclick="openAddItemModal()" style="white-space: nowrap; background: #0f172a; color: white; padding: 12px 20px; border-radius: 12px; font-weight: 800; font-size: 13px; border: none; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 10px 15px -3px rgba(15, 23, 42, 0.1); transition: all 0.2s;" onmouseover="this.style.transform='translateY(-1px)'; this.style.background='#1e293b'" onmouseout="this.style.transform='translateY(0)'; this.style.background='#0f172a'">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        Add Item
                    </button>
                </div>

                <div style="overflow-x: auto;">
                    <table class="stock-table">
                        <thead>
                            <tr>
                                <th width="60%">Item Details</th>
                                <th width="15%">Price</th>
                                <th width="25%" style="text-align: right;">Qty</th>
                            </tr>
                        </thead>
                        <tbody id="stockTableBody">
                            <?php foreach ($dishes as $dish): ?>
                                <tr class="stock-row category-<?php echo $dish['category_id']; ?>"
                                    data-name="<?php echo strtolower(htmlspecialchars($dish['item_name'])); ?>"
                                    data-category-name="<?php echo strtolower(htmlspecialchars($dish['category_name'])); ?>">
                                    <td>
                                        <div class="item-info">
                                            <h4>
                                                <?php echo htmlspecialchars($dish['item_name']); ?>
                                            </h4>
                                            <p style="margin-bottom: 5px;">
                                                <?php echo htmlspecialchars($dish['category_name']); ?>
                                            </p>
                                            <span class="tracking-badge">Tracking</span>
                                        </div>
                                    </td>
                                    <td style="font-weight: 600;">Rs.
                                        <?php echo number_format($dish['price']); ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <div style="display: flex; align-items: center; justify-content: flex-end;">
                                            <div class="qty-control" id="qty-container-<?php echo $dish['id']; ?>">
                                                <button class="qty-btn minus" onclick="adjustQty(<?php echo $dish['id']; ?>, -1)">−</button>
                                                <input type="number" 
                                                       value="<?php echo $dish['stock_count']; ?>" 
                                                       class="qty-input stock-qty-input-<?php echo $dish['id']; ?>"
                                                       oninput="showConfirm(<?php echo $dish['id']; ?>)">
                                                <button class="qty-btn plus" onclick="adjustQty(<?php echo $dish['id']; ?>, 1)">+</button>
                                            </div>
                                            <button id="confirm-btn-<?php echo $dish['id']; ?>" 
                                                    class="confirm-qty-btn" 
                                                    onclick="confirmUpdate(<?php echo $dish['id']; ?>)">
                                                Save Updates
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
    </div>
</div>

    </div>
</div>

<!-- Professional Modal Redesign -->
<div id="manageCatsModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <div>
                <h3 style="margin: 0; font-size: 18px; font-weight: 700;">Manage Categories</h3>
                <p style="margin: 4px 0 0 0; font-size: 12px; color: #94a3b8;">Choose which categories to show on your stock list.</p>
            </div>
            <button onclick="closeManageCatsModal()" style="background: transparent; border: none; color: white; cursor: pointer; padding: 4px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <div class="modal-body">
            <input type="text" id="modalCatSearch" class="modal-cat-search" placeholder="Search categories..." onkeyup="filterModalCategories()">
            
            <?php 
            $trackedList = array_filter($all_categories, fn($c) => ($c['track_stock'] ?? 0) == 1);
            $availableList = array_filter($all_categories, fn($c) => ($c['track_stock'] ?? 0) == 0);
            ?>

            <div id="trackedCatsSection">
                <?php if (!empty($trackedList)): ?>
                    <div class="cat-group-label" style="color: var(--accent-green); border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; margin-bottom: 12px;">Visible Categories</div>
                    <?php foreach ($trackedList as $ac): ?>
                        <div class="cat-toggle-card active modal-cat-row" data-name="<?php echo strtolower(htmlspecialchars($ac['category_name'])); ?>" style="cursor: default;">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div>
                                    <span style="font-weight: 700; color: var(--primary-navy); font-size: 14px; display: block;"><?php echo htmlspecialchars($ac['category_name']); ?></span>
                                    <span class="source-pill <?php echo $ac['restaurant_id'] == $current_branch_id ? 'pill-local' : 'pill-global'; ?>">
                                        <?php echo $ac['restaurant_id'] == $current_branch_id ? 'Local' : 'Reference'; ?>
                                    </span>
                                </div>
                            </div>
                            <button class="cat-remove-btn"
                                onclick="event.stopPropagation(); openRemoveCatConfirm(<?php echo $ac['id']; ?>, '<?php echo htmlspecialchars($ac['category_name'], ENT_QUOTES); ?>', this.closest('.cat-toggle-card'))"
                                title="Remove from tracking">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14H6L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M9 6V4h6v2"></path></svg>
                                Remove
                            </button>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div id="availableCatsSection">
                <?php if (!empty($availableList)): ?>
                    <div class="cat-group-label" style="color: var(--text-slate); border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; margin-bottom: 12px; margin-top: 24px;">Add Categories for Tracking</div>
                    <?php foreach ($availableList as $ac): ?>
                        <div class="cat-toggle-card modal-cat-row" data-name="<?php echo strtolower(htmlspecialchars($ac['category_name'])); ?>" onclick="toggleCatStock(<?php echo $ac['id']; ?>, 1, this)">
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div>
                                    <span style="font-weight: 600; color: #475569; font-size: 14px; display: block;"><?php echo htmlspecialchars($ac['category_name']); ?></span>
                                </div>
                            </div>
                            <div class="custom-checkbox"></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <div id="noModalResults" style="display: none; text-align: center; padding: 40px; color: var(--text-slate);">
                No matching categories found.
            </div>
        </div>
        <div style="padding: 16px 32px; border-top: 1px solid var(--border-slate); display: flex; justify-content: space-between; align-items: center; background: var(--bg-slate);">
            <div style="font-size: 10px; font-weight: 700; color: var(--text-slate); text-transform: uppercase; letter-spacing: 0.05em;">Saved Automatically</div>
            <button onclick="closeManageCatsModal()" style="background: var(--primary-navy); color: white; border: none; padding: 10px 32px; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; height: 40px;">Done</button>
        </div>
    </div>
</div>

<!-- Confirm Remove Category Modal -->
<div id="removeCatConfirmModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.4); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); z-index:999999; align-items:center; justify-content:center; perspective:1000px;">
    <div style="background:rgba(255,255,255,0.95); border-radius:24px; max-width:400px; width:92%; box-shadow:0 40px 100px -20px rgba(0,0,0,0.4); text-align:center; overflow:hidden; border:1px solid rgba(255,255,255,0.8); animation: confirmPop 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);">
        <div style="height:6px; background:#dc2626; width:100%;"></div>
        <div style="padding:40px 32px 32px;">
            <div style="width:64px; height:64px; background:#fee2e2; border-radius:32px; display:flex; align-items:center; justify-content:center; margin:0 auto 24px; border:2px solid #ef4444;">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14H6L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path><path d="M9 6V4h6v2"></path></svg>
            </div>
            
            <h3 style="font-size:22px; font-weight:900; color:#0f172a; margin:0 0 10px; letter-spacing:-0.5px; font-family: 'Inter', sans-serif;">Remove from Tracking?</h3>
            <p style="color:#64748b; font-size:14px; margin:0 0 20px; line-height:1.5;">This will remove <strong id="removeCatName" style="color:#0f172a; font-weight:900;"></strong> and all its items from stock tracking.</p>
            
            <div style="background:#fff1f2; border:1.5px dashed #fecaca; border-radius:16px; padding:16px; margin-bottom:32px;">
                <p style="color:#991b1b; font-size:13px; font-weight:700; margin:0; line-height:1.6;">
                    ⚠️ All listed items will show as <strong style="text-decoration:underline;">Always Available</strong> on the public menu.
                </p>
            </div>

            <div style="display:flex; gap:12px;">
                <button onclick="closeRemoveCatConfirm()" style="flex:1; padding:14px; border:2px solid #f1f5f9; border-radius:14px; font-weight:800; font-size:14px; cursor:pointer; background:white; color:#64748b; transition:all 0.2s;">Cancel</button>
                <button id="removeCatConfirmBtn" onclick="confirmRemoveCat()" style="flex:1; padding:14px; border:none; border-radius:14px; font-weight:800; font-size:14px; cursor:pointer; background:#dc2626; color:white; box-shadow:0 8px 20px -6px rgba(220,38,38,0.4); transition:all 0.2s;">Yes, Remove</button>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes confirmPop {
    0% { transform: scale(0.9) translateY(20px); opacity: 0; }
    100% { transform: scale(1) translateY(0); opacity: 1; }
}
</style>


<style>
@keyframes catModalIn {
    0% { transform: scale(0.95); opacity: 0; }
    100% { transform: scale(1); opacity: 1; }
}
.cat-toggle-card {
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1) !important;
}
.cat-toggle-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.05) !important;
}
.cat-remove-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: #fff1f2;
    color: #e11d48;
    border: 1.5px solid #fecdd3;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}
.cat-remove-btn:hover {
    background: #e11d48;
    color: white;
    border-color: #e11d48;
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(225,29,72,0.25);
}
.cat-add-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    background: #f0fdf4;
    color: #16a34a;
    border: 1.5px solid #bbf7d0;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}
.cat-add-btn:hover {
    background: #16a34a;
    color: white;
    border-color: #16a34a;
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(22,163,74,0.25);
}
</style>


<!-- Professional Add Item Modal -->
<div id="addItemModal" class="modal-overlay">
    <div class="modal-content" style="max-width: 500px;">
        <div class="modal-header">
            <h3 style="margin: 0; font-size: 18px; font-weight: 700;">New Menu Item</h3>
            <button onclick="closeAddItemModal()" style="background: transparent; border: none; color: white; cursor: pointer;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        </div>
        <form id="addItemForm" onsubmit="event.preventDefault(); submitAddItem();">
            <div class="modal-body">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--text-slate); text-transform: uppercase; margin-bottom: 6px;">Item Name</label>
                    <input type="text" name="item_name" required style="width: 100%; padding: 10px; border: 1px solid var(--border-slate); border-radius: 6px; outline: none;">
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 700; color: var(--text-slate); text-transform: uppercase; margin-bottom: 6px;">Price (Rs)</label>
                        <input type="number" name="price" required style="width: 100%; padding: 10px; border: 1px solid var(--border-slate); border-radius: 6px; outline: none;">
                    </div>
                    <div>
                        <label style="display: block; font-size: 12px; font-weight: 700; color: var(--text-slate); text-transform: uppercase; margin-bottom: 6px;">Initial Stock</label>
                        <input type="number" name="stock" value="0" style="width: 100%; padding: 10px; border: 1px solid var(--border-slate); border-radius: 6px; outline: none;">
                    </div>
                </div>
                <div>
                    <label style="display: block; font-size: 12px; font-weight: 700; color: var(--text-slate); text-transform: uppercase; margin-bottom: 6px;">Category</label>
                    <select name="category_id" required style="width: 100%; padding: 10px; border: 1px solid var(--border-slate); border-radius: 6px; outline: none; background: white;">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['category_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="padding: 24px 32px; border-top: 1px solid var(--border-slate); display: flex; gap: 12px; background: var(--bg-slate);">
                <button type="button" onclick="closeAddItemModal()" style="flex: 1; padding: 10px; border: 1px solid var(--border-slate); border-radius: 6px; font-weight: 700; cursor: pointer; background: white;">Cancel</button>
                <button type="submit" style="flex: 2; padding: 10px; border-radius: 6px; font-weight: 700; cursor: pointer; background: var(--primary-navy); color: white; border: none;">Create Item</button>
            </div>
        </form>
    </div>
</div>

<script>
    const CURRENT_BRANCH_ID = <?php echo (int) $current_branch_id; ?>;

    // Auto-select category on load
    window.addEventListener('load', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const catId = urlParams.get('cat');

        let targetEl = null;
        if (catId) {
            targetEl = document.querySelector(`.category-link[onclick*="filterStock(${catId},"]`);
        }

        // Default to first if not found or no param
        if (!targetEl) {
            targetEl = document.querySelector('.category-link');
        }

        if (targetEl) {
            // Extract ID from onclick if we defaulted
            if (!catId) {
                const match = targetEl.getAttribute('onclick').match(/filterStock\(([^,]+),/);
                if (match) {
                    // We don't need to manually trigger click if we just want to run the filter logic
                    // But clicking ensures visual active state + logic run
                    targetEl.click();
                    return;
                }
            }

            // If we have a specific catId from URL or found element
            // We can just call filterStock directly or click it
            // Clicking is safer to ensure UI state consistency
            targetEl.click();
        }
    });

    function filterStock(catId, element) {
        // Update URL
        const url = new URL(window.location);
        url.searchParams.set('cat', catId);
        window.history.replaceState({}, '', url);

        // Update navigation
        document.querySelectorAll('.category-link').forEach(el => el.classList.remove('active'));
        if (element) element.classList.add('active');

        // Filter rows
        const rows = document.querySelectorAll('.stock-row');
        rows.forEach(row => {
            if (catId === 'all' || row.classList.contains('category-' + catId)) {
                row.style.display = 'table-row';
            } else {
                row.style.display = 'none';
            }
        });
    }

    function searchItems() {
        const searchValue = document.getElementById('stockSearch').value.trim().toLowerCase();
        const searchKeywords = searchValue.split(/\s+/).filter(k => k.length > 0);
        const rows = document.querySelectorAll('.stock-row');
        
        // Search filtering logic.
        if (searchValue === '') {
            const activeLink = document.querySelector('.category-link.active');
            if (activeLink) activeLink.click();
            return;
        }

        rows.forEach(row => {
            const name = row.dataset.name || '';
            const catName = row.dataset.categoryName || '';
            
            // Original searchable text
            const originalText = `${name} ${catName}`.toLowerCase();
            // Normalized text (removes special chars like colons, dashes for flexible matching)
            const normalizedText = originalText.replace(/[^a-z0-9\s]/g, '');

            // Smart Match: Check if ALL keywords are found
            const allMatch = searchKeywords.every(keyword => {
                const cleanKeyword = keyword.replace(/[^a-z0-9]/g, '');
                return originalText.includes(keyword) || (cleanKeyword.length > 0 && normalizedText.includes(cleanKeyword));
            });

            if (allMatch) {
                row.style.display = 'table-row';
            } else {
                row.style.display = 'none';
            }
        });
    }



    function adjustQty(id, delta) {
        const input = document.querySelector(`.stock-qty-input-${id}`);
        let newVal = parseInt(input.value) + delta;
        if (newVal < 0) newVal = 0;
        input.value = newVal;
        showConfirm(id);
    }

    function showConfirm(id) {
        const input = document.querySelector(`.stock-qty-input-${id}`);

        // Remove leading zeros (e.g., "04" becomes "4")
        if (input.value.length > 1 && input.value.startsWith('0')) {
            input.value = input.value.replace(/^0+/, '');
        }

        // If empty, default to 0
        if (input.value === '') {
            input.value = 0;
        }

        const btn = document.getElementById(`confirm-btn-${id}`);
        btn.classList.add('visible');
    }

    function confirmUpdate(id) {
        const input = document.querySelector(`.stock-qty-input-${id}`);
        const btn = document.getElementById(`confirm-btn-${id}`);

        const cleanValue = parseInt(input.value) || 0;
        input.value = cleanValue; // Sync UI one last time

        // Disable temporarily
        btn.disabled = true;
        btn.style.opacity = '0.7';
        btn.textContent = 'Saving...';

        updateCount(id, cleanValue, function (success) {
            if (success) {
                // Success animation
                btn.classList.remove('visible');
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.textContent = 'Confirm';

                // Optional: visual flash on input to show it's saved
                const container = document.getElementById(`qty-container-${id}`);
                container.style.borderColor = '#10b981';
                setTimeout(() => container.style.borderColor = '', 1000);
            } else {
                btn.disabled = false;
                btn.style.opacity = '1';
                btn.textContent = 'Confirm';
                alert('Failed to save stock. Please try again.');
            }
        });
    }

    function updateCount(id, count, callback = null) {
        const formData = new FormData();
        formData.append('action', 'update_count');
        formData.append('item_id', id);
        formData.append('count', count);
        formData.append('restaurant_id', CURRENT_BRANCH_ID);

        fetch('itemstock.php', {
            method: 'POST',
            body: formData
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    if (callback) callback(true);
                } else {
                    if (callback) callback(false);
                    else alert('Error updating stock: ' + data.error);
                }
            })
            .catch(err => {
                if (callback) callback(false);
                else console.error(err);
            });
    }

    function openManageCatsModal() {
        document.getElementById('manageCatsModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    // ---- Add Category to Tracking ----
    function addCatToTracking(catId, btnEl) {
        btnEl.textContent = 'Adding...';
        btnEl.disabled = true;

        const formData = new FormData();
        formData.append('action', 'toggle_cat_stock');
        formData.append('cat_id', catId);
        formData.append('status', 1);
        formData.append('restaurant_id', CURRENT_BRANCH_ID);

        fetch('itemstock.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Remove the card from hidden list and reload to show in visible
                btnEl.closest('.cat-toggle-card').remove();
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Could not add category'));
                btnEl.textContent = 'Add';
                btnEl.disabled = false;
            }
        })
        .catch(err => {
            console.error(err);
            btnEl.textContent = 'Add';
            btnEl.disabled = false;
        });
    }
    // ----------------------------------

    // ---- Remove Category Confirm Flow ----
    let _removeCatId = null;
    let _removeCatCard = null;

    function openRemoveCatConfirm(catId, catName, cardEl) {
        _removeCatId = catId;
        _removeCatCard = cardEl;
        document.getElementById('removeCatName').textContent = catName;
        const modal = document.getElementById('removeCatConfirmModal');
        modal.style.display = 'flex';
        
        // Lock both body and parent modal
        document.body.style.overflow = 'hidden';
        const parentModalBody = document.querySelector('#manageCatsModal .modal-body');
        if (parentModalBody) parentModalBody.style.overflow = 'hidden';
    }

    function closeRemoveCatConfirm() {
        document.getElementById('removeCatConfirmModal').style.display = 'none';
        
        // Restore scrolling if manageCatsModal is still open
        if (document.getElementById('manageCatsModal').style.display === 'flex') {
            const parentModalBody = document.querySelector('#manageCatsModal .modal-body');
            if (parentModalBody) parentModalBody.style.overflow = 'auto';
        } else {
            document.body.style.overflow = '';
        }
        
        _removeCatId = null;
        _removeCatCard = null;
    }

    function confirmRemoveCat() {
        if (!_removeCatId) return;
        const btn = document.getElementById('removeCatConfirmBtn');
        btn.textContent = 'Removing...';
        btn.disabled = true;

        const formData = new FormData();
        formData.append('action', 'toggle_cat_stock');
        formData.append('cat_id', _removeCatId);
        formData.append('status', 0);
        formData.append('restaurant_id', CURRENT_BRANCH_ID);

        fetch('itemstock.php', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Remove card from UI
                if (_removeCatCard) _removeCatCard.remove();
                closeRemoveCatConfirm();
                // Reload to sync sidebar and item list
                location.reload();
            } else {
                alert('Error: ' + (data.error || 'Could not remove category'));
                btn.textContent = 'Yes, Remove';
                btn.disabled = false;
            }
        })
        .catch(err => {
            console.error(err);
            btn.textContent = 'Yes, Remove';
            btn.disabled = false;
        });
    }
    // --------------------------------------

    function closeManageCatsModal() {
        document.getElementById('manageCatsModal').style.display = 'none';
        document.body.style.overflow = '';
        location.reload();
    }

    function openAddItemModal() {
        document.getElementById('addItemModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }

    function closeAddItemModal() {
        document.getElementById('addItemModal').style.display = 'none';
        document.body.style.overflow = '';
    }

    function submitAddItem() {
        const form = document.getElementById('addItemForm');
        const formData = new FormData(form);
        formData.append('action', 'add_item');
        formData.append('branch_id', CURRENT_BRANCH_ID);

        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.textContent = 'Saving...';

        fetch('itemstock.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.error);
                btn.disabled = false;
                btn.textContent = 'Create Item';
            }
        });
    }

    function filterModalCategories() {
        const query = document.getElementById('modalCatSearch').value.toLowerCase();
        const rows = document.querySelectorAll('.modal-cat-row');
        let matched = 0;

        rows.forEach(row => {
            const name = row.dataset.name;
            if (name.includes(query)) {
                row.style.display = 'flex';
                matched++;
            } else {
                row.style.display = 'none';
            }
        });

        document.getElementById('noModalResults').style.display = matched === 0 ? 'block' : 'none';
    }

    function toggleCatStock(catId, status, element) {
        const checkbox = element.querySelector('.custom-checkbox');
        const isActivating = status === 1;
        
        // Visual feedback (Loading state)
        element.style.opacity = '0.6';
        element.style.pointerEvents = 'none';

        const formData = new FormData();
        formData.append('action', 'toggle_cat_stock');
        formData.append('cat_id', catId);
        formData.append('status', status);
        formData.append('restaurant_id', CURRENT_BRANCH_ID);

        fetch('itemstock.php', {
            method: 'POST',
            body: formData
        })
        .then(res => {
            if (!res.ok) throw new Error('Server Error: ' + res.status);
            return res.text();
        })
        .then(text => {
            let data;
            try {
                data = JSON.parse(text);
            } catch(e) {
                console.error('Invalid response:', text);
                throw new Error('Invalid JSON: ' + text.substring(0, 100));
            }
            
            element.style.opacity = '1';
            element.style.pointerEvents = 'auto';

            if (data.success) {
                if (isActivating) {
                    element.classList.add('active');
                    // Update onclick to toggle BACK (status 0)
                    element.onclick = function() { toggleCatStock(data.local_id || catId, 0, element); };
                    checkbox.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
                    checkbox.style.background = 'var(--accent-green)';
                    checkbox.style.borderColor = 'var(--accent-green)';
                } else {
                    element.classList.remove('active');
                    // Update onclick to toggle ON (status 1)
                    element.onclick = function() { toggleCatStock(catId, 1, element); };
                    checkbox.innerHTML = '';
                    checkbox.style.background = 'white';
                    checkbox.style.borderColor = 'var(--border-slate)';
                }
            } else {
                alert('Error: ' + (data.error || 'Server error'));
            }
        })
        .catch(err => {
            console.error(err);
            element.style.opacity = '1';
            element.style.pointerEvents = 'auto';
            alert('Debug: ' + err.message);
        });
    }


</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
