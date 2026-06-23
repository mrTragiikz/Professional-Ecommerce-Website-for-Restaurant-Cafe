<?php
/**
 * Gallery Management
 */

require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
requireAdminPin();

// Enforce Super Admin only access
if (($_SESSION['admin_role'] ?? '') !== 'super_admin') {
    header('Location: admin_dashboard.php');
    exit;
}

require_once __DIR__ . '/../config/db.php';

$galleryDir = __DIR__ . '/../uploads/gallery/';
if (!is_dir($galleryDir)) {
    mkdir($galleryDir, 0755, true);
}

// Handle Uploads
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['gallery_images'])) {
    $files = $_FILES['gallery_images'];
    $uploadedCount = 0;
    $errors = [];

    // Reorganize $_FILES.
    $fileList = [];
    if (is_array($files['name'])) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            if ($files['name'][$i] !== '') {
                $fileList[] = [
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i]
                ];
            }
        }
    } else {
        // Fallback.
        $fileList[] = $files;
    }

    foreach ($fileList as $file) {
        if ($file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (in_array($ext, $allowed)) {
                // Create unique filename
                $filename = 'gallery_' . time() . '_' . uniqid() . '.' . $ext;
                $destination = $galleryDir . $filename;

                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    $uploadedCount++;
                } else {
                    $errors[] = "Failed to move file: " . htmlspecialchars($file['name']);
                }
            } else {
                $errors[] = "Invalid type for file: " . htmlspecialchars($file['name']);
            }
        } else {
            $errors[] = "Error uploading file: " . htmlspecialchars($file['name']);
        }
    }

    // Redirect to clear.
    $redirectUrl = 'gallery.php?uploaded=' . $uploadedCount;
    if (!empty($errors)) {
        $redirectUrl .= '&errors=' . urlencode(implode('|', $errors));
    }
    header("Location: " . $redirectUrl);
    exit;
}

// Handle Delete
if (isset($_GET['delete'])) {
    $fileToDelete = basename($_GET['delete']);
    $filePath = $galleryDir . $fileToDelete;

    if (file_exists($filePath) && is_file($filePath)) {
        if (unlink($filePath)) {
            $success_msg = "Image deleted successfully!";
            // Redirect.
            header("Location: gallery.php?deleted=1");
            exit;
        } else {
            $error_msg = "Failed to delete image.";
        }
    }
}

// Set Messages from Redirect
if (isset($_GET['uploaded'])) {
    $count = (int) $_GET['uploaded'];
    if ($count > 0) {
        $success_msg = "$count image(s) uploaded successfully!";
    }
    if (isset($_GET['errors'])) {
        $error_msg = htmlspecialchars(str_replace('|', '<br>', urldecode($_GET['errors'])));
    }
}
if (isset($_GET['deleted'])) {
    $success_msg = "Image deleted successfully!";
}

// Get Images
$images = glob($galleryDir . '*.*');
if ($images) {
    $images = array_filter($images, function ($f) {
        return preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $f);
    });
} else {
    $images = [];
}

// Sort by newest first
usort($images, function ($a, $b) {
    return filemtime($b) - filemtime($a);
});

$pageTitle = 'Gallery Image';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .gallery-container {
        background: white;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        padding: 24px;
    }

    .upload-area {
        border: 2px dashed #e2e8f0;
        border-radius: 16px;
        padding: 40px;
        text-align: center;
        margin-bottom: 32px;
        transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        cursor: pointer;
        position: relative;
    }

    .upload-area:hover {
        border-color: #3b82f6;
        background: #f8fafc;
        transform: translateY(-2px);
        box-shadow: 0 10px 25px -5px rgba(59, 130, 246, 0.1);
    }

    .upload-icon {
        color: #64748b;
        margin-bottom: 12px;
    }

    .upload-text {
        color: #0f172a;
        font-weight: 600;
        margin-bottom: 4px;
    }

    .upload-hint {
        color: #64748b;
        font-size: 13px;
    }

    .gallery-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 24px;
    }

    .gallery-item {
        position: relative;
        border-radius: 16px;
        overflow: hidden;
        aspect-ratio: 1;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
        transition: all 0.5s cubic-bezier(0.16, 1, 0.3, 1);
        group: hover;
    }

    .gallery-item:hover {
        transform: translateY(-5px) scale(1.02);
        box-shadow: 0 20px 40px -8px rgba(0, 0, 0, 0.12);
        z-index: 10;
    }

    .gallery-img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .gallery-actions {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        background: linear-gradient(to top, rgba(0, 0, 0, 0.8), transparent);
        padding: 20px 12px 12px;
        display: flex;
        justify-content: flex-end;
        opacity: 0;
        transition: opacity 0.2s;
    }

    .gallery-item:hover .gallery-actions {
        opacity: 1;
    }

    .btn-delete {
        background: #ef4444;
        color: white;
        border: none;
        width: 32px;
        height: 32px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: background 0.2s;
    }

    .btn-delete:hover {
        background: #dc2626;
    }

    .alert {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 24px;
        font-size: 14px;
        font-weight: 500;
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
</style>

<div class="settings-container" style="max-width: 1200px;">
    <div class="settings-header">
        <h1 class="page-title">Gallery Image Management</h1>
        <p class="page-subtitle">Upload and manage images for the website gallery.</p>
    </div>

    <?php if (isset($success_msg)): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($success_msg); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($error_msg)): ?>
        <div class="alert alert-error">
            <?php echo $error_msg; ?>
        </div>
    <?php endif; ?>

    <div class="gallery-container">
        <!-- Upload Form -->
        <form action="" method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="upload-area" onclick="document.getElementById('fileInput').click()">
                <input type="file" name="gallery_images[]" id="fileInput" style="display: none" accept="image/*"
                    multiple onchange="validateFiles(this)">
                <div class="upload-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="1.5">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" stroke-linecap="round"
                            stroke-linejoin="round" />
                        <polyline points="17 8 12 3 7 8" stroke-linecap="round" stroke-linejoin="round" />
                        <line x1="12" y1="3" x2="12" y2="15" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
                <div class="upload-text">Click to Upload Images</div>
                <div class="upload-hint">Select multiple files securely (JPG, PNG, WEBP)</div>
            </div>
        </form>

        <!-- Gallery Grid -->
        <div class="gallery-grid">
            <?php foreach ($images as $path): ?>
                <?php $filename = basename($path); ?>
                <div class="gallery-item">
                    <img src="../uploads/gallery/<?php echo $filename; ?>" alt="Gallery Image" class="gallery-img"
                        loading="lazy">
                    <div class="gallery-actions">
                        <a href="#" class="btn-delete"
                            onclick="openDeleteModal('?delete=<?php echo urlencode($filename); ?>'); return false;"
                            title="Delete Image">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <path
                                    d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2-2v2"
                                    stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($images)): ?>

            <div style="text-align: center; padding: 40px; color: #64748b;">
                <p>No images found. Upload your first gallery image above.</p>
            </div>
        <?php endif; ?>
    </div>
</div>


<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path
                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                    stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </div>
        <h3 class="modal-title">Delete Image?</h3>
        <p class="modal-message">Are you sure you want to delete this image? This action cannot be undone.</p>
        <div class="modal-actions">
            <button onclick="closeDeleteModal()" type="button" class="btn-cancel">Cancel</button>
            <a id="confirmDeleteBtn" href="#" class="btn-confirm-delete">Delete</a>
        </div>
    </div>
</div>

<!-- Upload Confirmation Modal -->
<div id="uploadModal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-icon" style="background: #eff6ff; color: #3b82f6;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" stroke-linecap="round" stroke-linejoin="round" />
                <polyline points="17 8 12 3 7 8" stroke-linecap="round" stroke-linejoin="round" />
                <line x1="12" y1="3" x2="12" y2="15" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </div>
        <h3 class="modal-title">Confirm Upload</h3>
        <p class="modal-message" id="uploadMessage">You are about to upload images.</p>

        <!-- Preview Container -->
        <div id="uploadPreview"
            style="display: flex; gap: 8px; justify-content: center; margin-bottom: 20px; flex-wrap: wrap;">
            <!-- Previews will differ here -->
        </div>

        <div class="modal-actions">
            <button onclick="closeUploadModal()" type="button" class="btn-cancel">Cancel</button>
            <button onclick="confirmUpload()" type="button" class="btn-confirm-delete"
                style="background: #3b82f6; border-color: #3b82f6;">Upload Now</button>
        </div>
    </div>
</div>


<style>
    /* Modal Overlay - Ultra Smooth */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(15, 23, 42, 0.65);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        opacity: 0;
        visibility: hidden;
        transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
    }

    .modal-overlay.active {
        opacity: 1;
        visibility: visible;
    }

    /* Modal Content Box - Zero Latency Spring */
    .modal-content {
        background: #ffffff;
        padding: 32px;
        border-radius: 24px;
        width: 90%;
        max-width: 360px;
        text-align: center;
        transform: scale(0.9) translateY(20px);
        opacity: 0;
        transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.5);
        will-change: transform, opacity;
    }

    .modal-overlay.active .modal-content {
        transform: scale(1) translateY(0);
        opacity: 1;
    }

    /* Icon */
    .modal-icon {
        width: 48px;
        height: 48px;
        background: #fee2e2;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 16px;
        color: #dc2626;
    }

    /* Text */
    .modal-title {
        font-size: 18px;
        font-weight: 600;
        color: #111827;
        margin: 0 0 8px 0;
    }

    .modal-message {
        color: #6b7280;
        font-size: 14px;
        margin: 0 0 24px 0;
        line-height: 1.5;
    }

    /* Buttons */
    .modal-actions {
        display: flex;
        gap: 12px;
    }

    .btn-cancel,
    .btn-confirm-delete {
        flex: 1;
        padding: 10px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: none;
    }

    .btn-cancel {
        background: white;
        border: 1px solid #e5e7eb;
        color: #374151;
    }

    .btn-cancel:hover {
        background: #f9fafb;
        border-color: #d1d5db;
    }

    .btn-confirm-delete {
        background: #dc2626;
        color: white;
        border: 1px solid #dc2626;
    }

    .btn-confirm-delete:hover {
        background: #b91c1c;
        border-color: #b91c1c;
    }

    /* Modal Top */
    body.modal-open {
        overflow: hidden;
    }
</style>

<script>
    function openDeleteModal(url) {
        const modal = document.getElementById('deleteModal');
        const confirmBtn = document.getElementById('confirmDeleteBtn');
        if (confirmBtn && modal) {
            confirmBtn.href = url;
            modal.classList.add('active');
            document.body.classList.add('modal-open');
        }
    }

    function closeDeleteModal() {
        const modal = document.getElementById('deleteModal');
        if (modal) {
            modal.classList.remove('active');
            document.body.classList.remove('modal-open');
        }
    }

    // Close on outside click
    const deleteModal = document.getElementById('deleteModal');
    if (deleteModal) {
        deleteModal.addEventListener('click', function (e) {
            if (e.target === this) {
                closeDeleteModal();
            }
        });
    }

    // Close on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeDeleteModal();
            closeUploadModal();
        }
    });

    // Upload Logic.

    function validateFiles(input) {
        if (input.files && input.files.length > 0) {
            openUploadModal(input.files);
        }
    }

    function openUploadModal(files) {
        const modal = document.getElementById('uploadModal');
        const message = document.getElementById('uploadMessage');
        const preview = document.getElementById('uploadPreview');
        const count = files.length;

        // Update message
        message.textContent = `You selected ${count} image${count !== 1 ? 's' : ''} to upload.`;

        // Generate Previews (Max 4)
        preview.innerHTML = '';
        const maxPreview = 4;

        Array.from(files).slice(0, maxPreview).forEach(file => {
            const reader = new FileReader();
            reader.onload = function (e) {
                const img = document.createElement('img');
                img.src = e.target.result;
                img.style.width = '48px';
                img.style.height = '48px';
                img.style.objectFit = 'cover';
                img.style.borderRadius = '4px';
                img.style.border = '1px solid #e2e8f0';
                preview.appendChild(img);
            };
            reader.readAsDataURL(file);
        });

        if (count > maxPreview) {
            const more = document.createElement('div');
            more.textContent = `+${count - maxPreview}`;
            more.style.width = '48px';
            more.style.height = '48px';
            more.style.display = 'flex';
            more.style.alignItems = 'center';
            more.style.justifyContent = 'center';
            more.style.background = '#f1f5f9';
            more.style.borderRadius = '4px';
            more.style.color = '#64748b';
            more.style.fontSize = '12px';
            more.style.fontWeight = '600';
            preview.appendChild(more);
        }

        modal.classList.add('active');
        document.body.classList.add('modal-open');
    }

    function closeUploadModal() {
        const modal = document.getElementById('uploadModal');
        modal.classList.remove('active');
        document.body.classList.remove('modal-open');

        // Reset file input if cancelled so change event fires again
        const input = document.getElementById('fileInput');
        if (input) input.value = '';
    }

    function confirmUpload() {
        document.getElementById('uploadForm').submit();
    }

</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
