<?php
/**
 * Admin Detail - Manage Admin Accounts
 */

require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
requireAdminPin();

// Include database
global $pdo;

// Initialize message from session if exists
$message = $_SESSION['admin_success'] ?? '';
$error = $_SESSION['admin_error'] ?? '';
unset($_SESSION['admin_success'], $_SESSION['admin_error']);

// Handle Password Update for Current Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_own_password') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    
    if (empty($current_password) || empty($new_password)) {
        $error = "Both current and new passwords are required.";
    } else {
        try {
            // Verify current password first
            $stmt = $pdo->prepare("SELECT password_hash FROM admins WHERE id = ?");
            $stmt->execute([$_SESSION['admin_id']]);
            $admin = $stmt->fetch();
            
            if ($admin && password_verify($current_password, $admin['password_hash'])) {
                // Update with new password
                $passHash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE admins SET password_hash = ? WHERE id = ?");
                $stmt->execute([$passHash, $_SESSION['admin_id']]);
                
                $_SESSION['admin_success'] = "Your password has been updated successfully.";
                header("Location: admin_detail.php");
                exit;
            } else {
                $error = "Incorrect current password.";
            }
        } catch (PDOException $e) {
            $error = "Update Error: " . $e->getMessage();
        }
    }
}



// Handle Status Toggle
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $action = $_GET['toggle_status'];
    
    if ($id === $_SESSION['admin_id']) {
        $_SESSION['admin_error'] = "You cannot change the status of your own account while logged in.";
        header("Location: admin_detail.php");
        exit;
    }
    
    try {
        
        $stmtStatus = $pdo->prepare("SELECT username FROM admins WHERE id = ?");
        $stmtStatus->execute([$id]);
        $target = $stmtStatus->fetch();
        if ($target && $target['username'] === 'mishranabin_12') {
            $_SESSION['admin_error'] = "The primary admin account cannot be deactivated.";
            header("Location: admin_detail.php");
            exit;
        }

        if ($action === 'deactivate') {
            $lockUntil = '2099-12-31 23:59:59';
            $stmt = $pdo->prepare("UPDATE admins SET lock_until = ?, failed_login_attempts = 5 WHERE id = ?");
            $stmt->execute([$lockUntil, $id]);
            $_SESSION['admin_success'] = "Admin account deactivated.";
        } else if ($action === 'activate') {
            $stmt = $pdo->prepare("UPDATE admins SET lock_until = NULL, failed_login_attempts = 0 WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['admin_success'] = "Admin account activated.";
        }
        header("Location: admin_detail.php");
        exit;
    } catch (PDOException $e) {
        $error = "Status update failed: " . $e->getMessage();
    }
}

// Handle Delete Admin
if (isset($_GET['delete_admin'])) {
    $id = intval($_GET['delete_admin']);
    
    if ($id === $_SESSION['admin_id']) {
        $_SESSION['admin_error'] = "You cannot delete your own account while logged in.";
        header("Location: admin_detail.php");
        exit;
    }
    
    try {
        
        $stmtDel = $pdo->prepare("SELECT username FROM admins WHERE id = ?");
        $stmtDel->execute([$id]);
        $target = $stmtDel->fetch();
        if ($target && $target['username'] === 'mishranabin_12') {
            $_SESSION['admin_error'] = "The primary admin account cannot be deleted.";
            header("Location: admin_detail.php");
            exit;
        }

        $pdo->prepare("DELETE FROM admins WHERE id = ?")->execute([$id]);
        $_SESSION['admin_success'] = "Admin account deleted permanently.";
        header("Location: admin_detail.php");
        exit;
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

try {
    // We only fetch current admin info for the password change
    $stmt = $pdo->prepare("SELECT id, username, last_login FROM admins WHERE id = ?");
    $stmt->execute([$_SESSION['admin_id']]);
    $currentAdminData = $stmt->fetch();
} catch (PDOException $e) {
    $error = "Database error fetching account details.";
}

$pageTitle = 'My Account Security';
require_once __DIR__ . '/includes/header.php';
?>

<div class="rider-admin-container" style="max-width: 800px; margin: 0 auto; padding-top: 20px;">
    <div style="display: flex; flex-direction: column; gap: 30px;">
        
        <!-- Change Own Password Section -->
        <div>
            <div style="background: white; border-radius: 20px; border: 1px solid rgba(0,0,0,0.06); padding: 40px; box-shadow: 0 10px 30px rgba(0,0,0,0.04);">
                <div style="display: flex; align-items: center; gap: 20px; margin-bottom: 30px;">
                    <div style="width: 64px; height: 64px; border-radius: 16px; background: #eef2ff; color: #4f46e5; display: flex; align-items: center; justify-content: center;">
                        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 style="margin: 0; font-size: 1.5rem; font-weight: 800; color: #1e293b;">Account Security</h3>
                        <p style="margin: 5px 0 0 0; font-size: 0.95rem; color: #64748b; font-weight: 500;">
                            Hi, <strong style="color: #4f46e5;">@<?php echo htmlspecialchars($currentAdminData['username'] ?? 'Admin'); ?></strong>. Update your dashboard login credentials below.
                        </p>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-success animate-fade-in" style="background: #f0fdf4; border: 1px solid #bbf7d0; color: #16a34a; padding: 16px; border-radius: 12px; margin-bottom: 25px; display: flex; align-items: center; gap: 12px;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        <div style="font-weight: 600;"><?php echo htmlspecialchars($message); ?></div>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error animate-fade-in" style="background: #fef2f2; border: 1px solid #fecaca; color: #ef4444; padding: 16px; border-radius: 12px; margin-bottom: 25px; display: flex; align-items: center; gap: 12px;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
                        <div style="font-weight: 600;"><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_own_password">
                    
                    <div style="margin-bottom: 20px; position: relative;">
                        <label style="display: block; font-size: 0.9rem; font-weight: 700; color: #475569; margin-bottom: 10px;">Current Password</label>
                        <div style="position: relative;">
                            <input type="password" name="current_password" required placeholder="Enter your current password" style="width: 100%; padding: 14px 45px 14px 16px; border: 2px solid #f1f5f9; border-radius: 12px; outline: none; transition: all 0.2s; font-size: 15px; background: #f8fafc;" onfocus="this.style.borderColor='#6366f1'; this.style.background='white'; this.style.boxShadow='0 0 0 4px rgba(99, 102, 241, 0.1)'" onblur="this.style.borderColor='#f1f5f9'; this.style.background='#f8fafc'; this.style.boxShadow='none'">
                            <button type="button" class="toggle-password" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; padding: 5px; color: #94a3b8; cursor: pointer; display: flex; align-items: center;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 30px; position: relative;">
                        <label style="display: block; font-size: 0.9rem; font-weight: 700; color: #475569; margin-bottom: 10px;">New Password</label>
                        <div style="position: relative;">
                            <input type="password" name="new_password" required minlength="4" placeholder="Enter at least 4 characters" style="width: 100%; padding: 14px 45px 14px 16px; border: 2px solid #f1f5f9; border-radius: 12px; outline: none; transition: all 0.2s; font-size: 15px; background: #f8fafc;" onfocus="this.style.borderColor='#6366f1'; this.style.background='white'; this.style.boxShadow='0 0 0 4px rgba(99, 102, 241, 0.1)'" onblur="this.style.borderColor='#f1f5f9'; this.style.background='#f8fafc'; this.style.boxShadow='none'">
                            <button type="button" class="toggle-password" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; padding: 5px; color: #94a3b8; cursor: pointer; display: flex; align-items: center;">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                        </div>
                    </div>
                    
                    <button type="submit" style="width: 100%; padding: 16px; background: #4f46e5; color: white; border: none; border-radius: 12px; font-weight: 700; font-size: 16px; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);" onmouseover="this.style.background='#4338ca'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 8px 15px rgba(79, 70, 229, 0.3)'" onmouseout="this.style.background='#4f46e5'; this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(79, 70, 229, 0.25)'">
                        Save Secure Password
                    </button>
                </form>

                <?php if (isSuperAdmin()): ?>
                    <div style="margin-top: 30px; padding-top: 30px; border-top: 1px solid #f1f5f9; text-align: center;">
                        <a href="manage_branch_admins.php" style="display: inline-flex; align-items: center; gap: 8px; color: #6366f1; font-weight: 700; text-decoration: none; font-size: 0.9rem; padding: 8px 16px; background: #f5f7ff; border-radius: 10px; transition: all 0.2s;" onmouseover="this.style.background='#eef2ff'; this.style.transform='translateX(5px)'" onmouseout="this.style.background='#f5f7ff'; this.style.transform='translateX(0)'">
                            Go to User Management
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>


<script>
    document.querySelectorAll('.toggle-password').forEach(button => {
        button.addEventListener('click', function() {
            const input = this.closest('div').querySelector('input');
            const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
            input.setAttribute('type', type);
            
            if (type === 'text') {
                this.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24M1 1l22 22"></path></svg>';
            } else {
                this.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
            }
        });
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

