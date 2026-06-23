<?php
/**
 * Manage Branch Admins - Super Admin Section
 */

require_once __DIR__ . '/includes/auth.php';
requireSuperAdmin();

// Include database
global $pdo;



$message = $_SESSION['admin_success'] ?? '';
$error = $_SESSION['admin_error'] ?? '';
unset($_SESSION['admin_success'], $_SESSION['admin_error']);

// Handle Admin Creation/Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_admin' || $_POST['action'] === 'edit_admin') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = trim($_POST['role'] ?? 'branch_admin');
        $branchId = !empty($_POST['branch_id']) ? intval($_POST['branch_id']) : null;
        $adminId = intval($_POST['admin_id'] ?? 0);

        // ID 1 is the main super admin.

        if (empty($username)) {
            $error = "Username is required.";
        } else {
            try {
                if ($_POST['action'] === 'add_admin') {
                    if (empty($password)) throw new Exception("Password is required for new accounts.");
                    $passHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO admins (username, password_hash, role, branch_id) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$username, $passHash, $role, $branchId]);


                    $_SESSION['admin_success'] = "Admin account '$username' created successfully.";
                } else {
                    if (!empty($password)) {
                        $passHash = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE admins SET username = ?, password_hash = ? WHERE id = ?");
                        $stmt->execute([$username, $passHash, $adminId]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE admins SET username = ? WHERE id = ?");
                        $stmt->execute([$username, $adminId]);
                    }
                    $_SESSION['admin_success'] = "Admin account '$username' updated successfully.";
                }
                header("Location: manage_branch_admins.php");
                exit;
            } catch (Exception $e) {
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $error = "Username already exists.";
                } else {
                    $error = "Database Error: " . $e->getMessage();
                }
            }
        }
    }
}

// Handle Admin Deletion
if (isset($_GET['delete_admin'])) {
    $id = intval($_GET['delete_admin']);
    try {
        // Prevent deleting yourself
        if ($id === $_SESSION['admin_id']) {
            $error = "Cannot delete your own account.";
        } else {
            $pdo->prepare("DELETE FROM admins WHERE id = ?")->execute([$id]);
            $_SESSION['admin_success'] = "Admin account deleted successfully.";
            header("Location: manage_branch_admins.php");
            exit;
        }
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

/** 
 * AUTOMATIC BACKGROUND FLUSH: 
 * Silently remove any orphaned branch admin accounts (those with no assigned branch)
 * to keep the list clean automatically as requested.
 */
try {
    $pdo->exec("DELETE FROM admins WHERE role = 'branch_admin' AND branch_id IS NULL");
} catch (Exception $e) { }

// Get all admins (excluding base super admin)
$stmt = $pdo->query("SELECT a.*, b.name as branch_name FROM admins a LEFT JOIN branches b ON a.branch_id = b.id WHERE a.id != 1 ORDER BY a.id ASC");
$admins = $stmt->fetchAll();

$pageTitle = 'Manage Branch Admins';
require_once __DIR__ . '/includes/header.php';
?>

<div class="rider-admin-container">
    <div class="admin-actions-flex">
        <h2 style="margin: 0; color: var(--text-primary);">Manage Branch Admins</h2>
    </div>


    <?php if ($message): ?>
        <div class="alert alert-success animate-fade-in">
            <div class="alert-content"><?php echo htmlspecialchars($message); ?></div>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-error animate-fade-in">
            <div class="alert-content"><?php echo htmlspecialchars($error); ?></div>
        </div>
    <?php endif; ?>

    <div class="orders-section">
        <div class="table-responsive">
            <table class="rider-table">
                <thead>
                    <tr>
                        <th style="width: 80px;">ID</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Assigned Branch</th>
                        <th>Last Login</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $admin): ?>
                        <tr class="rider-row">
                            <td class="rider-id">#<?php echo $admin['id']; ?></td>
                            <td style="font-weight: 700; color: var(--text-primary);"><?php echo htmlspecialchars($admin['username']); ?></td>
                            <td>
                                <span class="badge-status <?php echo $admin['role'] === 'super_admin' ? 'badge-active' : 'badge-inactive'; ?>">
                                    <?php echo strtoupper(str_replace('_', ' ', $admin['role'])); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($admin['role'] === 'super_admin'): ?>
                                    <em style="color: #94a3b8; font-style: normal; font-weight: 500;">(All Branches)</em>
                                <?php else: ?>
                                    <span style="font-weight: 600; color: #475569;"><?php echo htmlspecialchars($admin['branch_name'] ?: 'None'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 13px; color: #64748b;"><?php echo ($admin['last_login'] ?? '') ?: 'Never'; ?></td>
                            <td style="text-align: right;">
                                <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                    <button class="action-btn btn-info" onclick='openEditAdminModal(<?php echo json_encode($admin); ?>)'>Edit</button>
                                    <?php if ($admin['id'] != $_SESSION['admin_id']): ?>
                                        <button type="button" class="action-btn btn-danger" onclick="confirmDeleteAdmin('?delete_admin=<?php echo $admin['id']; ?>')">Delete</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add/Edit Admin Modal -->
<div id="adminModal" class="modal-overlay">
    <div class="modal-card">
        <div class="modal-card-header">
            <h3 id="modalTitle">Add New Admin</h3>
            <button class="close-modal" onclick="closeAdminModal()">&times;</button>
        </div>
        <form action="" method="POST" class="modal-form">
            <input type="hidden" name="action" id="formAction" value="add_admin">
            <input type="hidden" name="admin_id" id="admin_id">

            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" id="admin_username" class="modern-input" placeholder="e.g. branch_manager_1" required>
            </div>

            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" id="admin_password" class="modern-input" placeholder="New password (leave blank to keep current)">
            </div>

            <input type="hidden" name="role" id="admin_role" value="branch_admin">

            <div id="branchInfoGroup" style="margin-top: 15px; padding: 12px 16px; background: #f1f5f9; border-radius: 14px; display: none;">
                <label style="margin-bottom: 4px; opacity: 0.7;">Linked Branch</label>
                <div id="display_branch_name" style="font-weight: 700; color: #1e293b;"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAdminModal()">Cancel</button>
                <button type="submit" class="btn btn-primary" style="padding: 12px 24px; border-radius: 12px;">Save Admin</button>
            </div>
        </form>
    </div>
</div>

<!-- Custom Confirmation Modal -->
<div id="confirmModal" class="modal-overlay" style="z-index: 2000; display: none;">
    <div class="modal-card confirm-modal-box" style="display: block;">
        <div class="confirm-icon">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
        </div>
        <h3 id="confirmTitle" style="margin-top: 0; margin-bottom: 12px; font-size: 22px; font-weight: 800; color: #0f172a;">Are you sure?</h3>
        <p id="confirmMessage" style="color: #64748b; font-size: 15px; line-height: 1.6; margin-bottom: 32px;">This action cannot be undone. Do you want to proceed?</p>
        <div style="display: flex; gap: 12px; justify-content: center;">
            <button type="button" class="btn btn-secondary" style="flex: 1;" onclick="closeConfirmModal()">Cancel</button>
            <button type="button" id="confirmOkBtn" class="btn btn-danger" style="flex: 1;">Yes, Delete</button>
        </div>
    </div>
</div>



<script>
function openEditAdminModal(admin) {
    document.getElementById('modalTitle').innerText = 'Edit Admin Account';
    document.getElementById('formAction').value = 'edit_admin';
    document.getElementById('admin_id').value = admin.id;
    document.getElementById('admin_username').value = admin.username;
    document.getElementById('admin_password').placeholder = 'Change password (leave blank to keep current)';
    document.getElementById('admin_password').required = false;
    
    // Display linked branch name
    const branchInfo = document.getElementById('branchInfoGroup');
    const branchDisplay = document.getElementById('display_branch_name');
    if (admin.role !== 'super_admin' && admin.branch_name) {
        branchInfo.style.display = 'block';
        branchDisplay.textContent = admin.branch_name;
    } else {
        branchInfo.style.display = 'none';
    }
    
    document.getElementById('adminModal').style.display = 'flex';
}

function closeAdminModal() {
    document.getElementById('adminModal').style.display = 'none';
}

function confirmDeleteAdmin(url) {
    showConfirm(
        'Delete Admin?', 
        'Are you sure you want to permanently delete this admin account?', 
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

// Handle outside clicks
window.onclick = function(event) {
    const adminModal = document.getElementById('adminModal');
    const confirmModal = document.getElementById('confirmModal');
    
    if (event.target === adminModal) {
        closeAdminModal();
    } else if (event.target === confirmModal) {
        closeConfirmModal();
    }
}
</script>

<style>
.rider-admin-container { 
    padding: 2.5rem; 
    max-width: 1400px; 
    margin: 0 auto; 
    animation: fadeIn 0.4s ease-out;
}

.admin-actions-flex { 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    margin-bottom: 2rem; 
}

/* Premium Dashboard Table */
.orders-section {
    background: white;
    border-radius: 20px;
    box-shadow: 0 4px 25px rgba(0, 0, 0, 0.04) !important;
    border: 1px solid #f1f5f9 !important;
    overflow: hidden;
}

.rider-table { 
    width: 100%; 
    border-collapse: separate; 
    border-spacing: 0; 
}

.rider-table th { 
    padding: 20px 24px; 
    text-align: left; 
    background: #fdfdfd; 
    color: #94a3b8; 
    font-weight: 700; 
    text-transform: uppercase; 
    font-size: 11px; 
    letter-spacing: 0.08em; 
    border-bottom: 1px solid #f1f5f9; 
}

.rider-table td { 
    padding: 22px 24px; 
    vertical-align: middle; 
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    font-size: 14px;
}

.rider-row {
    transition: all 0.2s ease;
}

.rider-row:hover {
    background-color: #f8fafc;
}

.rider-id { 
    font-family: 'JetBrains Mono', 'Courier New', monospace; 
    color: #94a3b8; 
    font-weight: 600; 
    font-size: 13px;
}

/* Badge Styling */
.badge-status {
    padding: 6px 14px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 800;
    display: inline-block;
    letter-spacing: 0.02em;
}

.badge-active { 
    background: #eef2ff; 
    color: #4f46e5; 
    border: 1px solid #e0e7ff;
}

.badge-inactive { 
    background: #f0fdf4; 
    color: #15803d; 
    border: 1px solid #dcfce7;
}

/* Modal Overlay & Card */
.modal-overlay { 
    position: fixed; 
    top: 0; 
    left: 0; 
    width: 100%; 
    height: 100%; 
    background: rgba(15, 23, 42, 0.6); 
    backdrop-filter: blur(8px);
    display: none; 
    align-items: center; 
    justify-content: center; 
    z-index: 1000; 
}

.modal-card { 
    background: white; 
    padding: 2.5rem; 
    border-radius: 24px; 
    width: 100%; 
    max-width: 500px; 
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); 
    animation: modalSlideUp 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.modal-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
}

.modal-card-header h3 {
    margin: 0;
    font-weight: 700;
    color: #1e293b;
}

.close-modal {
    background: #f1f5f9;
    border: none;
    font-size: 24px;
    line-height: 1;
    cursor: pointer;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    color: #64748b;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
}

.close-modal:hover {
    background: #e2e8f0;
    color: #1e293b;
}

.form-group label {
    display: block;
    font-weight: 600;
    color: #475569;
    font-size: 13px;
    margin-bottom: 8px;
}

.modern-input { 
    width: 100%; 
    padding: 14px 16px; 
    border: 1px solid #e2e8f0; 
    border-radius: 14px; 
    font-size: 14px;
    transition: all 0.2s;
    background: #f8fafc;
    color: #1e293b;
}

.modern-input:focus {
    outline: none;
    border-color: #6366f1;
    background: white;
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    margin-top: 2rem;
}

/* Action Buttons Customization */
.action-btn {
    padding: 10px 18px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 700;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-info { 
    background: #f1f5f9; 
    color: #475569; 
}
.btn-info:hover { 
    background: #e2e8f0; 
    color: #1e293b;
}

.btn-danger { 
    background: #fff1f2; 
    color: #e11d48; 
}
.btn-danger:hover { 
    background: #ffe4e6; 
}

@keyframes modalSlideUp {
    from { transform: translateY(30px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(5px); }
    to { opacity: 1; transform: translateY(0); }
}

@media (max-width: 992px) {
    .rider-table th:nth-child(5), .rider-table td:nth-child(5) { display: none; }
}

@media (max-width: 768px) {
    .rider-admin-container { padding: 1.5rem; }
    .admin-actions-flex { flex-direction: column; align-items: flex-start; gap: 1.5rem; }
    .rider-table th:nth-child(4), .rider-table td:nth-child(4) { display: none; }
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
