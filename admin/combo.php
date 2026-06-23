<?php
/**
 * Combo Offers Management
 * 
 * Manages items in the 'Combo Offers' category.
 * Integrates directly with menu_items table for cart compatibility.
 */

require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
requireAdminPin();
require_once __DIR__ . '/../config/db.php';

// Branch Selection Logic
$current_branch_id = getAdminBranchId() ?: 1; // Default to branch 1 if none selected

// Ensure 'Combo Offers' category exists
$comboCatId = 0;
try {
    $stmt = $pdo->prepare("SELECT id FROM menu_categories WHERE category_name = 'Combo Offers' AND restaurant_id = ?");
    $stmt->execute([$current_branch_id]);
    $cat = $stmt->fetch();

    if ($cat) {
        $comboCatId = $cat['id'];
    } else {
        // Create it
        $stmt = $pdo->prepare("INSERT INTO menu_categories (category_key, category_name, display_order, is_active, restaurant_id) VALUES ('combo-offers', 'Combo Offers', 0, 1, ?)");
        $stmt->execute([$current_branch_id]);
        $comboCatId = $pdo->lastInsertId();
    }
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_combo') {
    $item_id = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $price = (float) $_POST['price']; // This is the SELLING price
    $old_price = !empty($_POST['old_price']) ? (float) $_POST['old_price'] : null; // Original price
    $end_time = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if (!empty($title)) {
        try {
            // Handle Image Upload
            $image_path = null;
            $should_update_image = false;

            if (isset($_FILES['combo_image']) && $_FILES['combo_image']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['combo_image']['tmp_name'];
                $fileName = $_FILES['combo_image']['name'];
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($fileTmpPath);

                $allowedMimeTypes = [
                    'image/jpeg' => 'jpg',
                    'image/jpg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                    'image/gif' => 'gif'
                ];

                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                $validExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

                if (in_array($fileExt, $validExtensions) || array_key_exists($mimeType, $allowedMimeTypes)) {
                    $ext = $allowedMimeTypes[$mimeType] ?? ($fileExt === 'jpeg' ? 'jpg' : $fileExt);
                    
                    $uploadDir = __DIR__ . '/../assets/combo-offers/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    $newFileName = uniqid('combo_', true) . '.' . $ext;
                    $destination = $uploadDir . $newFileName;

                    if (move_uploaded_file($fileTmpPath, $destination)) {
                        chmod($destination, 0644);
                        $image_path = 'assets/combo-offers/' . $newFileName;
                        $should_update_image = true;
                    } else {
                        throw new Exception("Failed to move uploaded file.");
                    }
                } else {
                    throw new Exception("Invalid file type. Allowed: JPG, PNG, WEBP, GIF.");
                }
            } elseif (isset($_POST['delete_image']) && $_POST['delete_image'] == '1') {
                $image_path = null;
                $should_update_image = true;
            }

            if ($item_id > 0) {
                // Update
                if ($should_update_image) {
                    $stmt = $pdo->prepare("UPDATE menu_items SET item_name = ?, item_description = ?, price = ?, old_price = ?, image_path = ?, offer_end_time = ?, is_active = ? WHERE id = ? AND category_id = ? AND restaurant_id = ?");
                    $stmt->execute([$title, $description, $price, $old_price, $image_path, $end_time, $is_active, $item_id, $comboCatId, $current_branch_id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE menu_items SET item_name = ?, item_description = ?, price = ?, old_price = ?, offer_end_time = ?, is_active = ? WHERE id = ? AND category_id = ? AND restaurant_id = ?");
                    $stmt->execute([$title, $description, $price, $old_price, $end_time, $is_active, $item_id, $comboCatId, $current_branch_id]);
                }
                $_SESSION['success_msg'] = "Combo updated successfully!";
            } else {
                // Insert
                $stmt = $pdo->prepare("INSERT INTO menu_items (category_id, item_name, item_description, price, old_price, image_path, offer_end_time, is_active, display_order, restaurant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?)");
                $stmt->execute([$comboCatId, $title, $description, $price, $old_price, $image_path, $end_time, $is_active, $current_branch_id]);
                $_SESSION['success_msg'] = "Combo created successfully!";
            }

            header("Location: combo.php");
            exit;
        } catch (Exception $e) {
            $_SESSION['error_msg'] = "Error saving combo: " . $e->getMessage();
            header("Location: combo.php");
            exit;
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_combo') {
    $item_id = (int) $_POST['item_id'];
    if ($item_id > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM menu_items WHERE id = ? AND category_id = ? AND restaurant_id = ?");
            $stmt->execute([$item_id, $comboCatId, $current_branch_id]);
            $_SESSION['success_msg'] = "Combo deleted successfully.";
            header("Location: combo.php");
            exit;
        } catch (Exception $e) {
            $_SESSION['error_msg'] = "Error deleting combo: " . $e->getMessage();
            header("Location: combo.php");
            exit;
        }
    }
}

// Fetch Combos (items in combo category)
$combos = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE category_id = ? AND restaurant_id = ? ORDER BY id DESC");
    $stmt->execute([$comboCatId, $current_branch_id]);
    $combos = $stmt->fetchAll();
} catch (PDOException $e) {
    // 
}

$pageTitle = 'Combo Offers';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* Global Font Override */
    body,
    button,
    input,
    textarea,
    select {
        font-family: 'Inter', sans-serif !important;
    }

    .panel-card {
        background: white;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        padding: 24px;
        margin-bottom: 24px;
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
    .form-textarea {
        width: 100%;
        padding: 12px 16px;
        border: 1.5px solid #e2e8f0;
        border-radius: 12px;
        font-size: 14px;
        background: #f8fafc;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        font-family: 'Inter', sans-serif;
        color: #1e293b;
    }

    .form-input:focus,
    .form-textarea:focus {
        background: white;
        border-color: #6366f1;
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.12);
        outline: none;
        transform: translateY(-1px);
    }

    /* Date Input Specific Styling */
    input[type="datetime-local"].form-input {
        color: #334155;
        font-weight: 500;
        cursor: pointer;
        position: relative;
    }

    /* Improve Webkit Date Picker */
    input[type="datetime-local"]::-webkit-calendar-picker-indicator {
        background: transparent;
        bottom: 0;
        color: transparent;
        cursor: pointer;
        height: auto;
        left: 0;
        position: absolute;
        right: 0;
        top: 0;
        width: auto;
    }

    /* Add a custom icon for date picker if needed, or just style the default better */
    input[type="datetime-local"] {
        appearance: none;
        -webkit-appearance: none;
    }

    /* Revert webkit trick if it breaks usability, instead just style standard */
    input[type="datetime-local"].form-input {
        padding-right: 12px;
        /* Ensure readability */
    }

    .btn {
        padding: 12px 20px;
        border-radius: 12px;
        font-weight: 600;
        cursor: pointer;
        border: none;
        font-size: 14px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
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

    .combo-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 20px;
    }

    .combo-card {
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        overflow: hidden;
        transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        position: relative;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    }

    .combo-card:hover {
        box-shadow: 0 20px 40px -8px rgba(0, 0, 0, 0.12);
        border-color: #cbd5e1;
        transform: translateY(-5px) scale(1.01);
    }

    .combo-img {
        height: 180px;
        width: 100%;
        object-fit: cover;
        background: #f1f5f9;
        display: block;
    }

    .combo-body {
        padding: 20px;
    }

    .combo-title {
        font-weight: 700;
        font-size: 16px;
        margin-bottom: 6px;
        color: #0f172a;
    }

    .combo-desc {
        font-size: 13px;
        color: #64748b;
        margin-bottom: 16px;
        line-height: 1.5;
        height: 40px;
        overflow: hidden;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
    }

    .combo-price {
        font-weight: 700;
        color: #059669;
        font-size: 18px;
    }

    .combo-old-price {
        text-decoration: line-through;
        color: #94a3b8;
        font-size: 13px;
        margin-left: 8px;
        font-weight: 500;
    }

    .combo-meta {
        font-size: 11px;
        color: #64748b;
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px solid #f1f5f9;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    /* Modal - ultra smooth no latency */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(15, 23, 42, 0.65);
        z-index: 1000;
        display: flex;
        align-items: center;
        justify-content: center;
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        opacity: 0;
        visibility: hidden;
        transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .modal-overlay.open {
        opacity: 1;
        visibility: visible;
    }

    .modal-box {
        background: #ffffff;
        border-radius: 24px;
        width: 100%;
        max-width: 520px;
        padding: 36px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.3), inset 0 1px 0 rgba(255,255,255,0.5);
        transform: scale(0.9) translateY(20px);
        opacity: 0;
        transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        will-change: transform, opacity;
    }

    .modal-overlay.open .modal-box {
        transform: scale(1) translateY(0);
        opacity: 1;
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
            <h1 class="page-title">Combo Offers</h1>
            <p class="page-subtitle">Create distinct combo offers. These appear on the homepage and are orderable.</p>
        </div>
        <button class="btn btn-primary" onclick="openComboModal()">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 5v14M5 12h14" />
            </svg>
            New Combo
        </button>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success"
            style="background:#dcfce7; color:#166534; padding:12px; border-radius:8px; margin-bottom:20px;">
            <?php echo htmlspecialchars($success_msg); ?>
        </div>
    <?php endif; ?>

    <div class="combo-grid">
        <?php foreach ($combos as $combo): ?>
            <div class="combo-card">
                <?php if ($combo['image_path']): ?>
                    <img src="../<?php echo htmlspecialchars($combo['image_path']); ?>" class="combo-img" alt="Combo">
                <?php else: ?>
                    <div class="combo-img" style="display:flex;align-items:center;justify-content:center;color:#cbd5e1;">No
                        Image</div>
                <?php endif; ?>

                <div class="combo-body">
                    <div class="combo-title"><?php echo htmlspecialchars($combo['item_name']); ?></div>
                    <div class="combo-desc"><?php echo htmlspecialchars($combo['item_description']); ?></div>
                    <div>
                        <span class="combo-price">Rs. <?php echo number_format($combo['price']); ?></span>
                        <?php if ($combo['old_price'] > $combo['price']): ?>
                            <span class="combo-old-price">Rs. <?php echo number_format($combo['old_price']); ?></span>
                            <span
                                style="background:#ef4444;color:white;font-size:10px;padding:2px 4px;border-radius:4px;margin-left:4px;">OFFER</span>
                        <?php endif; ?>
                    </div>

                    <div class="combo-meta">
                        <div>
                            <?php if ($combo['is_active']): ?>
                                <span
                                    style="background:#dcfce7; color:#166534; padding:2px 6px; border-radius:4px; font-weight:600;">Active</span>
                            <?php else: ?>
                                <span
                                    style="background:#f1f5f9; color:#64748b; padding:2px 6px; border-radius:4px; font-weight:600;">Hidden</span>
                            <?php endif; ?>
                            <?php if (!empty($combo['offer_end_time'])): ?>
                                <div style="margin-top:4px;color:#ea580c;">Ends:
                                    <?php echo date('M j, g:ia', strtotime($combo['offer_end_time'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <!-- Pass data to JS function carefully -->
                            <button onclick='editCombo(<?php
                            // Create safe object for JS
                            echo json_encode([
                                "id" => $combo['id'],
                                "title" => $combo['item_name'],
                                "desc" => $combo['item_description'],
                                "price" => $combo['price'],
                                "old_price" => $combo['old_price'],
                                "image" => $combo['image_path'],
                                "end" => $combo['offer_end_time'] ? date('Y-m-d\TH:i', strtotime($combo['offer_end_time'])) : '',
                                "active" => $combo['is_active']
                            ]);
                            ?>)'
                                style="background:none;border:none;color:#4f46e5;cursor:pointer;margin-right:8px;">Edit</button>
                            <form method="POST" style="display:inline;" class="delete-form">
                                <input type="hidden" name="action" value="delete_combo">
                                <input type="hidden" name="item_id" value="<?php echo $combo['id']; ?>">
                                <button type="submit"
                                    style="background:none;border:none;color:#ef4444;cursor:pointer;">Delete</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

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

<!-- Modal -->
<div id="comboModal" class="modal-overlay">
    <div class="modal-box">
        <h3 id="modalTitle" style="margin-top:0; margin-bottom:20px;">Add New Combo</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="save_combo">
            <input type="hidden" name="item_id" id="comboId">

            <div class="form-group">
                <label class="form-label">Combo Title</label>
                <input type="text" name="title" id="comboTitle" class="form-input" required>
            </div>

            <div class="form-group">
                <label class="form-label">Description</label>
                <textarea name="description" id="comboDesc" class="form-textarea"></textarea>
            </div>

            <div style="display:flex; gap:16px;">
                <div class="form-group" style="flex:1;">
                    <label class="form-label">Offer Price (Rs.)</label>
                    <input type="number" step="0.01" name="price" id="comboPrice" class="form-input" required>
                </div>
                <div class="form-group" style="flex:1;">
                    <label class="form-label">Original Price (Rs.)</label>
                    <input type="number" step="0.01" name="old_price" id="comboOldPrice" class="form-input">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Ends At (Optional)</label>
                <input type="datetime-local" name="end_time" id="comboEnd" class="form-input">
            </div>

            <div class="form-group">
                <label class="form-label">Image</label>

                <!-- Current Image Preview -->
                <div id="currentImageContainer"
                    style="display:none; margin-bottom:10px; align-items:center; gap:10px; padding:10px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0;">
                    <img id="currentImagePreview" src="" alt="Current Image"
                        style="width:50px; height:50px; object-fit:cover; border-radius:6px;">
                    <div style="flex:1;">
                        <div style="font-size:12px; color:#64748b;">Current Image</div>
                        <div style="font-size:13px; font-weight:500; color:#334155; word-break:break-all;"
                            id="currentImageName">filename.jpg</div>
                    </div>
                    <button type="button" onclick="removeCurrentImage()"
                        style="color:#ef4444; background:white; border:1px solid #ef4444; padding:4px 8px; border-radius:4px; font-size:12px; cursor:pointer; font-weight:600;">Remove</button>
                </div>

                <input type="hidden" name="delete_image" id="deleteImageFlag" value="0">
                <input type="file" name="combo_image" id="comboImageInput" class="form-input"
                    accept=".jpg, .jpeg, .png, .webp, .gif">
                <div style="font-size:11px; color:#94a3b8; margin-top:4px;">Supported: JPG, PNG, WEBP, GIF</div>
            </div>

            <div class="form-group">
                <label style="display:flex; align-items:center; gap:8px; font-size:14px; cursor:pointer;">
                    <input type="checkbox" name="is_active" id="comboActive" value="1" checked>
                    Listing is Active
                </label>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:24px;">
                <button type="button" class="btn" style="background:#f1f5f9; color:#475569;"
                    onclick="closeComboModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Combo</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openComboModal() {
        document.getElementById('comboModal').classList.add('open');
        document.documentElement.classList.add('modal-open');
        document.body.classList.add('modal-open');
        document.getElementById('modalTitle').textContent = 'Add New Combo';
        document.getElementById('comboId').value = '';
        document.getElementById('comboTitle').value = '';
        document.getElementById('comboDesc').value = '';
        document.getElementById('comboPrice').value = '';
        document.getElementById('comboOldPrice').value = '';
        document.getElementById('comboEnd').value = '';
        document.getElementById('comboActive').checked = true;

        // Reset Image UI
        document.getElementById('currentImageContainer').style.display = 'none';
        document.getElementById('deleteImageFlag').value = '0';
        document.getElementById('comboImageInput').value = '';
    }

    function closeComboModal() {
        document.getElementById('comboModal').classList.remove('open');
        document.documentElement.classList.remove('modal-open');
        document.body.classList.remove('modal-open');
    }

    function editCombo(combo) {
        document.getElementById('comboModal').classList.add('open');
        document.documentElement.classList.add('modal-open');
        document.body.classList.add('modal-open');
        document.getElementById('modalTitle').textContent = 'Edit Combo';

        document.getElementById('comboId').value = combo.id;
        document.getElementById('comboTitle').value = combo.title;
        document.getElementById('comboDesc').value = combo.desc;
        document.getElementById('comboPrice').value = combo.price;
        document.getElementById('comboOldPrice').value = combo.old_price;
        document.getElementById('comboEnd').value = combo.end;
        document.getElementById('comboActive').checked = (combo.active == 1);

        // Handle Image UI
        const imgContainer = document.getElementById('currentImageContainer');
        const imgPreview = document.getElementById('currentImagePreview');
        const imgName = document.getElementById('currentImageName');
        const deleteFlag = document.getElementById('deleteImageFlag');
        const fileInput = document.getElementById('comboImageInput');

        deleteFlag.value = '0'; // Reset delete flag
        fileInput.value = ''; // Reset file input

        if (combo.image) {
            imgContainer.style.display = 'flex';
            imgPreview.src = '../' + combo.image;
            imgName.textContent = combo.image.split('/').pop();
        } else {
            imgContainer.style.display = 'none';
        }
    }

    function removeCurrentImage() {
        showConfirm(
            'Remove Image?', 
            'Are you sure you want to remove the current image?', 
            'Yes, Remove',
            function() {
                document.getElementById('currentImageContainer').style.display = 'none';
                document.getElementById('deleteImageFlag').value = '1';
            }
        );
    }

    // Custom Confirm Logic
    let confirmCallback = null;

    function showConfirm(title, message, btnText, callback) {
        document.getElementById('confirmTitle').textContent = title;
        document.getElementById('confirmMessage').textContent = message;
        document.getElementById('confirmOkBtn').textContent = btnText;
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

    // Handle delete forms
    document.addEventListener('click', function(e) {
        if (e.target.closest('.delete-form button')) {
            e.preventDefault();
            const form = e.target.closest('.delete-form');
            showConfirm(
                'Delete Combo?', 
                'Are you sure you want to permanently delete this combo offer?', 
                'Yes, Delete',
                () => form.submit()
            );
        }
    });

    // Close on outside click
    window.onclick = function(event) {
        const comboModal = document.getElementById('comboModal');
        const confirmModal = document.getElementById('confirmModal');
        
        if (event.target === comboModal) {
            closeComboModal();
        } else if (event.target === confirmModal) {
            closeConfirmModal();
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
