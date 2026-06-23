<?php
/**
 * Offer Management
 */

require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
requireAdminPin();
require_once __DIR__ . '/../config/db.php';

// Branch Selection Logic
$current_branch_id = getAdminBranchId() ?: 1; // Default to branch 1 if none selected

// AJAX handling.
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $item_id = (int) $_POST['item_id'];

    try {
        if ($_POST['action'] === 'update_offer') {
            $discount_price = (float) $_POST['price'];
            $offer_end_time = !empty($_POST['offer_end_time']) ? $_POST['offer_end_time'] : null;

            // Fetch current state to determine original price
            $stmt = $pdo->prepare("SELECT price, old_price FROM menu_items WHERE id = ? AND restaurant_id = ?");
            $stmt->execute([$item_id, $current_branch_id]);
            $current = $stmt->fetch();

            if (!$current) {
                throw new Exception("Item not found");
            }

            // Determine rates.
            $standard_price = ($current['old_price'] > 0) ? (float) $current['old_price'] : (float) $current['price'];

            // Update logic.

            if ($discount_price > 0 && $discount_price < $standard_price) {
                // Valid Offer
                $updateStmt = $pdo->prepare("UPDATE menu_items SET price = ?, old_price = ?, offer_end_time = ? WHERE id = ? AND restaurant_id = ?");
                $updateStmt->execute([$discount_price, $standard_price, $offer_end_time, $item_id, $current_branch_id]);
            } else {
                // Remove Offer / Reset (If price is 0, empty, or >= standard)
                $updateStmt = $pdo->prepare("UPDATE menu_items SET price = ?, old_price = NULL, offer_end_time = NULL WHERE id = ? AND restaurant_id = ?");
                $updateStmt->execute([$standard_price, $item_id, $current_branch_id]); // Restore standard price
            }

            echo json_encode(['success' => true]);
            exit;
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Ensure columns exist.
try {
    $colStmt = $pdo->query("SHOW COLUMNS FROM menu_items LIKE 'offer_end_time'");
    if (!$colStmt->fetch()) {
        $pdo->exec("ALTER TABLE menu_items ADD COLUMN offer_end_time DATETIME DEFAULT NULL AFTER old_price");
    }
} catch (PDOException $e) {
    // ignore
}

// Fetch Categories
$categories = [];
try {
    $stmt = $pdo->prepare("SELECT id, category_name FROM menu_categories WHERE category_name != 'Combo Offers' AND restaurant_id = ? ORDER BY display_order ASC");
    $stmt->execute([$current_branch_id]);
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Categories fetch error: " . $e->getMessage());
}

// Fetch Dishes
$dishes = [];
try {
    $dishesStmt = $pdo->prepare("
        SELECT m.id, m.item_name, m.price, m.old_price, m.offer_end_time, m.is_active, m.category_id, c.category_name 
        FROM menu_items m 
        JOIN menu_categories c ON m.category_id = c.id 
        WHERE c.category_name != 'Combo Offers' AND m.restaurant_id = ?
        ORDER BY c.display_order ASC, m.display_order ASC
    ");
    $dishesStmt->execute([$current_branch_id]);
    $dishes = $dishesStmt->fetchAll();
} catch (PDOException $e) {
    error_log("Dishes fetch error: " . $e->getMessage());
}

$pageTitle = 'Make Offer';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .stock-grid {
        display: grid;
        grid-template-columns: 300px 1fr;
        gap: 24px;
        align-items: start;
    }

    .panel-card {
        background: white;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        padding: 20px;
        margin-bottom: 24px;
    }

    .panel-title {
        font-size: 16px;
        font-weight: 700;
        margin-bottom: 16px;
        color: #0f172a;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .category-nav {
        display: flex;
        flex-direction: column;
        gap: 6px;
        max-height: calc(100vh - 250px);
        overflow-y: auto;
        padding-right: 5px;
    }

    .category-link {
        padding: 10px 12px;
        border-radius: 8px;
        color: #475569;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.2s;
        border: 1px solid transparent;
        cursor: pointer;
    }

    .category-link:hover {
        background: #f1f5f9;
        color: #0f172a;
    }

    .category-link.active {
        background: #0f172a;
        color: white;
        font-weight: 600;
    }

    .stock-table {
        width: 100%;
        border-collapse: collapse;
    }

    .stock-table th {
        text-align: left;
        padding: 12px;
        background: #f8fafc;
        font-size: 11px;
        text-transform: uppercase;
        color: #64748b;
        font-weight: 700;
        border-bottom: 1px solid #e2e8f0;
        white-space: nowrap;
    }

    .stock-table td {
        padding: 16px 12px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }

    .item-info h4 {
        margin: 0 0 2px 0;
        font-size: 15px;
        color: #0f172a;
    }

    .item-info p {
        margin: 0;
        font-size: 12px;
        color: #64748b;
    }

    .search-bar {
        margin-bottom: 20px;
        position: relative;
    }

    .search-input {
        width: 100%;
        padding: 10px 15px 10px 40px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 14px;
    }

    .search-icon {
        position: absolute;
        left: 14px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
    }

    /* Input Controls */
    .price-input-group {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        /* Allow wrapping on small screens */
    }

    .price-field {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .price-label {
        font-size: 10px;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
    }

    .price-input {
        width: 100px;
        padding: 8px;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 600;
        color: #0f172a;
        transition: all 0.2s;
    }

    .date-input {
        width: 160px;
        /* Slightly wider for date */
        padding: 7px;
        /* Adjust padding to match height */
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 13px;
        color: #0f172a;
        font-family: inherit;
    }

    .price-input:focus,
    .date-input:focus {
        outline: none;
        border-color: #6366f1;
        box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
    }

    .price-input.old-price {
        color: #ef4444;
        text-decoration: line-through;
    }

    .price-input.new-price {
        color: #059669;
    }

    /* Confirm Button Styling */
    .confirm-btn {
        display: none;
        align-items: center;
        justify-content: center;
        background: #10b981;
        color: white;
        border: none;
        border-radius: 6px;
        padding: 8px 12px;
        margin-left: 8px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        height: 38px;
        align-self: flex-end;
    }

    .confirm-btn:hover {
        background: #059669;
        transform: translateY(-1px);
    }

    .confirm-btn.visible {
        display: inline-flex;
    }
</style>

<div class="settings-container" style="max-width: 1200px;">
    <div class="settings-header">
        <h1 class="page-title">Make Offer</h1>
        <p class="page-subtitle">Set discount prices for your menu items. Enter Old Rate to show discount.</p>
    </div>

    <div class="stock-grid">
        <!-- Sidebar -->
        <div class="panel-card" style="position: sticky; top: 100px;">
            <div class="panel-title">Categories</div>
            <div class="category-nav">
                <div class="category-link active" onclick="filterStock('all', this)">All Categories</div>
                <?php foreach ($categories as $cat): ?>
                    <div class="category-link" onclick="filterStock(<?php echo $cat['id']; ?>, this)">
                        <?php echo htmlspecialchars($cat['category_name']); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Item List -->
        <div>
            <div class="panel-card">
                <div class="search-bar">
                    <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                    <input type="text" id="offerSearch" class="search-input" placeholder="Search dish name..."
                        onkeyup="searchItems()">
                </div>

                <div style="overflow-x: auto;">
                    <table class="stock-table">
                        <thead>
                            <tr>
                                <th width="25%">Item Details</th>
                                <th width="20%">Standard Rate</th>
                                <th width="20%">Offer Price</th>
                                <th width="25%">Offer Ends</th>
                                <th width="10%"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dishes as $dish):
                                // Calculate Standard and Current Prices
                                $isOfferActive = ($dish['old_price'] > 0);
                                // If offer is active, Standard Rate is old_price. Else it's current price.
                                $standardRate = $isOfferActive ? $dish['old_price'] : $dish['price'];
                                // Discount input simply shows the CURRENT price. If user lowers it, it becomes an offer.
                                $currentPrice = $dish['price'];
                                ?>
                                <tr class="stock-row category-<?php echo $dish['category_id']; ?>"
                                    data-name="<?php echo strtolower(htmlspecialchars($dish['item_name'])); ?>"
                                    data-category-name="<?php echo strtolower(htmlspecialchars($dish['category_name'])); ?>">
                                    <td>
                                        <div class="item-info">
                                            <h4>
                                                <?php echo htmlspecialchars($dish['item_name']); ?>
                                                <?php if ($isOfferActive):
                                                    $off = round((($standardRate - $currentPrice) / $standardRate) * 100);
                                                    ?>
                                                    <span
                                                        style="background: #E31837; color: white; padding: 2px 6px; border-radius: 4px; font-size: 10px; margin-left: 6px; vertical-align: middle;">
                                                        <?php echo $off; ?>% OFF
                                                    </span>
                                                <?php endif; ?>
                                            </h4>
                                            <p>
                                                <?php echo htmlspecialchars($dish['category_name']); ?>
                                            </p>
                                        </div>
                                    </td>
                                    <td>
                                        <!-- Read Only Standard Rate -->
                                        <div class="price-field">
                                            <div class="standard-rate-display"
                                                style="font-size: 15px; font-weight: 700; color: #64748b; padding: 8px 0;">
                                                Rs. <?php echo number_format($standardRate, 0); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="price-field">
                                            <input type="number" class="price-input new-price"
                                                id="price-<?php echo $dish['id']; ?>"
                                                value="<?php echo ($isOfferActive) ? $currentPrice : ''; ?>"
                                                placeholder="<?php echo $standardRate; ?>" step="0.01" min="0"
                                                oninput="showConfirm(<?php echo $dish['id']; ?>)">
                                        </div>
                                    </td>
                                    <td>
                                        <div class="price-field">
                                            <input type="datetime-local" class="date-input"
                                                id="expiry-<?php echo $dish['id']; ?>"
                                                value="<?php echo $dish['offer_end_time'] ? date('Y-m-d\TH:i', strtotime($dish['offer_end_time'])) : ''; ?>"
                                                oninput="showConfirm(<?php echo $dish['id']; ?>)">
                                        </div>
                                    </td>
                                    <td>
                                        <button id="confirm-btn-<?php echo $dish['id']; ?>" class="confirm-btn"
                                            onclick="confirmUpdate(<?php echo $dish['id']; ?>)">
                                            Save
                                        </button>
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

<script>
    function filterStock(catId, element) {
        document.querySelectorAll('.category-link').forEach(el => el.classList.remove('active'));
        if (element) element.classList.add('active');

        const rows = document.querySelectorAll('.stock-row');
        rows.forEach(row => {
            if (catId === 'all' || row.classList.contains('category-' + catId)) {
                row.style.display = 'table-row';
            } else {
                row.style.display = 'none';
            }
        });

        // Re-run search if value exists to ensure search filters apply within category
        const searchQuery = document.getElementById('offerSearch').value;
        if (searchQuery) searchItems();
    }

    function searchItems() {
        const searchValue = document.getElementById('offerSearch').value.trim().toLowerCase();
        const searchKeywords = searchValue.split(/\s+/).filter(k => k.length > 0);
        const rows = document.querySelectorAll('.stock-row');
        
        // Search filtering.
        if (searchValue === '') {
            const activeCatEl = document.querySelector('.category-link.active');
            const match = activeCatEl.getAttribute('onclick').match(/filterStock\(([^,]+),/);
            const activeCatId = match ? match[1] : 'all';
            filterStock(activeCatId.replace(/'/g, ""), activeCatEl);
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

    function showConfirm(id) {
        const btn = document.getElementById(`confirm-btn-${id}`);
        btn.classList.add('visible');
    }

    function confirmUpdate(id) {
        const priceInput = document.getElementById(`price-${id}`);
        const expiryInput = document.getElementById(`expiry-${id}`);
        const btn = document.getElementById(`confirm-btn-${id}`);

        const price = parseFloat(priceInput.value) || 0;
        const expiry = expiryInput.value;

        btn.disabled = true;
        btn.textContent = 'Saving...';

        const formData = new FormData();
        formData.append('action', 'update_offer');
        formData.append('item_id', id);

        // If price is NaN (empty) or 0, send 0. Backend should treat <= 0 as "Reset Offer"
        formData.append('price', (isNaN(price) || price <= 0) ? 0 : price);
        formData.append('offer_end_time', expiry);

        fetch('offer.php', {
            method: 'POST',
            body: formData
        })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    btn.classList.remove('visible');
                    btn.disabled = false;
                    btn.textContent = 'Save';

                    // Refresh UI on save.
                    // location.reload(); 

                    // Standard Rate display remains static based on the item.
                    // Only Discount Price and Badge update.

                } else {
                    alert('Error saving offer: ' + data.error);
                    btn.disabled = false;
                    btn.textContent = 'Save';
                }
            })
            .catch(err => {
                console.error(err);
                btn.disabled = false;
                btn.textContent = 'Save';
            });
    }

    // Auto-refresh on expiry.
    document.addEventListener('DOMContentLoaded', function () {
        const now = new Date().getTime();
        const inputs = document.querySelectorAll('input[type="datetime-local"]');

        inputs.forEach(input => {
            if (input.value) {
                const expiry = new Date(input.value).getTime();
                if (expiry > now) {
                    const diff = expiry - now;
                    // Prevent setting huge timeouts (limit to 24h)
                    if (diff < 86400000) {
                        setTimeout(() => {
                            location.reload();
                        }, diff + 1500); // Add 1.5s buffer
                    }
                }
            }
        });
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>