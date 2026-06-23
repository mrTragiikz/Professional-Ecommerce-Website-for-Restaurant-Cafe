<?php
/**
 * Admin Authentication Helper
 */

// Load centralized session config and start admin session (JK_ADMIN_SESS, 7-day lifetime)
if (!defined('JK_SESSION_NAME_ADMIN')) {
    require_once __DIR__ . '/../../config/session_config.php';
}
jk_start_admin_session();

/**
 * GET CURRENT BUSINESS DATE (Reset at 4:00 AM)
 * If it's 2:00 AM on March 20, it returns 2026-03-19.
 * If it's 5:00 AM on March 20, it returns 2026-03-20.
 */
function getBusinessDate()
{
    // The cut-off for the next business day is 4:00 AM
    return date('Y-m-d', strtotime('-4 hours'));
}

/**
 * GET START AND END TIMESTAMPS FOR A BUSINESS DAY
 * Useful for BETWEEN queries.
 * @param string|null $date The business date (YYYY-MM-DD). If null, uses current business date.
 * @return array ['start', 'end']
 */
function getBusinessDayRange($date = null)
{
    if (!$date) $date = getBusinessDate();
    
    $start = $date . ' 04:00:00';
    $end = date('Y-m-d', strtotime($date . ' +1 day')) . ' 03:59:59';
    
    return ['start' => $start, 'end' => $end];
}

// Load database configuration with error handling
try {
    if (!file_exists(__DIR__ . '/../../config/db.php')) {
        throw new Exception('Database configuration file not found: config/db.php');
    }
    require_once __DIR__ . '/../../config/db.php';
} catch (Exception $e) {
    error_log('Admin auth - Database config error: ' . $e->getMessage());
    // Don't throw - let the calling script handle it
} catch (Error $e) {
    error_log('Admin auth - Database config fatal error: ' . $e->getMessage());
    // Don't throw - let the calling script handle it
}

/**
 * Check if admin is logged in
 */
function isAdminLoggedIn()
{
    return isset($_SESSION['admin_id']) && isset($_SESSION['admin_username']) && isset($_SESSION['admin_role']);
}

/**
 * Check if current admin is a Super Admin
 */
function isSuperAdmin()
{
    return isAdminLoggedIn() && $_SESSION['admin_role'] === 'super_admin';
}

/**
 * Get current admin's branch ID.
 * For super admins: also checks the ?branch= URL parameter so the selected
 * branch is respected across all pages without per-page manual overrides.
 */
function getAdminBranchId()
{
    // Branch admins: always return their fixed session branch
    if (!isSuperAdmin()) {
        return $_SESSION['admin_branch_id'] ?? null;
    }

    // Super admins: check URL parameter first, then session, then null (all branches)
    if (isset($_GET['branch']) && $_GET['branch'] !== '' && $_GET['branch'] !== 'all') {
        $branchFromUrl = intval($_GET['branch']);
        if ($branchFromUrl > 0) {
            $_SESSION['selected_admin_branch'] = $branchFromUrl;
            return $branchFromUrl;
        }
    }
    
    // If 'all' is explicitly requested
    if (isset($_GET['branch']) && $_GET['branch'] === 'all') {
        unset($_SESSION['selected_admin_branch']);
        return null;
    }

    // Fall back to stored selection or session branch
    $id = $_SESSION['selected_admin_branch'] ?? $_SESSION['admin_branch_id'] ?? null;
    
    // VALIDATION: If the ID is not positive or doesn't exist, we must fetch a default
    // We do this check regardless of whether it's null or 0 to be safe.
    if (!is_numeric($id) || intval($id) <= 0) {
        $id = null;
    }

    // If NO branch is selected and we're a Super Admin, default to the first active branch
    if ($id === null) {
        try {
            global $pdo;
            $stmt = $pdo->query("SELECT id FROM branches WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
            $first = $stmt->fetch();
            if ($first) {
                $id = (int)$first['id'];
                $_SESSION['selected_admin_branch'] = $id; // Store it as the persistent default
            }
        } catch (Exception $e) {
            error_log("getAdminBranchId Critical Error: " . $e->getMessage());
        }
    }
    
    return $id;
}

/**
 * Require admin login (redirect to login if not logged in)
 */
function requireAdminLogin()
{
    // 1. Check if session exists
    if (!isAdminLoggedIn()) {
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') 
                  || isset($_GET['ajax']);
                  
        if ($isAjax) {
            header('HTTP/1.1 401 Unauthorized');
            exit('Unauthorized');
        }

        // Redirect to login page - assume we're in admin folder structure
        if (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) {
            header('Location: admin.php');
        } else {
            header('Location: /admin/admin.php');
        }
        exit;
    }

    // 2. Verify account still exists (Handle Dismantled branches)
    $admin = getCurrentAdmin();
    if (!$admin) {
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') 
                  || isset($_GET['ajax']);
                  
        if ($isAjax) {
            header('HTTP/1.1 401 Unauthorized');
            exit('Unauthorized');
        }

        // Branch was dismantled and users deleted
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        // Force redirect to registration page for new setup as requested
        header('Location: ../auth/signup.php?error=branch_dismantled');
        exit;
    }
}

/**
 * Require Super Admin role
 */
function requireSuperAdmin()
{
    requireAdminLogin();
    if (!isSuperAdmin()) {
        header('Location: admin_dashboard.php?error=unauthorized_access');
        exit;
    }
}

/**
 * Verify admin credentials
 */
function verifyAdminLogin($username, $password)
{
    global $pdo;

    try {
        $stmt = $pdo->prepare("SELECT id, username, password_hash, role, branch_id, failed_login_attempts, lock_until FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if (!$admin) {
            return ['success' => false, 'error' => 'Invalid username or password'];
        }

        // Check if account is locked
        if ($admin['lock_until'] && strtotime($admin['lock_until']) > time()) {
            return ['success' => false, 'error' => 'Account is temporarily locked. Please try again later.'];
        }

        // Verify password
        if (!password_verify($password, $admin['password_hash'])) {
            // Increment failed attempts
            $failedAttempts = $admin['failed_login_attempts'] + 1;
            $lockUntil = null;

            // Lock account after 5 failed attempts (15 minutes)
            if ($failedAttempts >= 5) {
                $lockUntil = date('Y-m-d H:i:s', time() + (15 * 60));
            }

            $stmt = $pdo->prepare("UPDATE admins SET failed_login_attempts = ?, lock_until = ? WHERE id = ?");
            $stmt->execute([$failedAttempts, $lockUntil, $admin['id']]);

            return ['success' => false, 'error' => 'Invalid username or password'];
        }

        // Successful login - reset failed attempts and update last login
        $stmt = $pdo->prepare("UPDATE admins SET failed_login_attempts = 0, lock_until = NULL, last_login = NOW() WHERE id = ?");
        $stmt->execute([$admin['id']]);

        return [
            'success'   => true, 
            'admin_id'  => $admin['id'], 
            'username'  => $admin['username'],
            'role'      => $admin['role'],
            'branch_id' => $admin['branch_id']
        ];

    } catch (PDOException $e) {
        error_log("Admin login error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Database error occurred'];
    }
}

/**
 * Get current admin info
 */
function getCurrentAdmin()
{
    if (!isAdminLoggedIn()) {
        return null;
    }

    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT a.id, a.username, a.last_login, a.role, a.branch_id, b.name as branch_name 
                               FROM admins a 
                               LEFT JOIN branches b ON a.branch_id = b.id 
                               WHERE a.id = ?");
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch();
        if ($admin) return $admin;

        // Fallback: query without branch join (branches table may not exist yet)
        $stmt = $pdo->prepare("SELECT id, username, last_login, role, branch_id FROM admins WHERE id = ?");
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch();
        if ($admin) {
            $admin['branch_name'] = null;
            return $admin;
        }
        return null;
    } catch (PDOException $e) {
        error_log("Get admin error: " . $e->getMessage());
        return null;
    }
}

/**
 * Check if admin PIN is verified for the session
 */
function isAdminPinVerified()
{
    // If not a Super Admin, we don't require the secondary PIN (vendors/branch admins don't need it)
    if (!isSuperAdmin()) {
        return true;
    }
    
    // For super admins, check if they've verified their PIN for this session
    return isset($_SESSION['admin_pin_verified']) && $_SESSION['admin_pin_verified'] === true;
}

/**
 * Require admin PIN (redirect for server-side verification)
 */
function requireAdminPin()
{
    if (!isAdminPinVerified()) {
        // If not verified, redirect to dashboard which will then prompt for PIN
        // Alternatively, show an error. Redirecting to dashboard is smoother if they reached here via URL direct entry.
        header('Location: admin_dashboard.php?error=pin_required');
        exit;
    }
}

