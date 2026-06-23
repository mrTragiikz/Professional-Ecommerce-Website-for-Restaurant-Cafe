<?php
/**
 * User Authentication Helper Functions
 * 
 * This file contains all authentication-related functions including:
 * - User login/logout
 * - Session management
 * - User creation and retrieval
 * - Email verification checks
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/security.php';

/**
 * Check if user is logged in
 * 
 * @return bool True if logged in, false otherwise
 */
function isUserLoggedIn()
{
    initSecureSession();
    return isset($_SESSION['user_id']) && isset($_SESSION['user_email']);
}

/**
 * Get the current customer's active branch ID.
 * Priority: Logged-in User Profile > Session > First Active Branch in DB
 * 
 * @return int|null Branch ID
 */
function getCurrentCustomerBranchId() {
    // 1. Logged-in User Choice
    $user = getCurrentUser();
    if ($user && isset($user['branch_id']) && (int)$user['branch_id'] > 0) {
        return (int)$user['branch_id'];
    }

    // 2. Session Choice (Guest Picker)
    if (isset($_SESSION['customer_branch_id']) && (int)$_SESSION['customer_branch_id'] > 0) {
        return (int)$_SESSION['customer_branch_id'];
    }

    // 3. Database Fallback (First Active Branch)
    static $defaultBranchId = null;
    if ($defaultBranchId !== null) return $defaultBranchId;

    global $pdo;
    if (isset($pdo) && $pdo !== null) {
        try {
            // Use a simple query to get the first active branch
            $stmt = $pdo->query("SELECT id FROM branches WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
            $first = $stmt->fetchColumn();
            if ($first) {
                $defaultBranchId = (int)$first;
                return $defaultBranchId;
            }
        } catch (Throwable $e) {
            error_log("getCurrentCustomerBranchId DB error: " . $e->getMessage());
        }
    }

    return 1; // Absolute hardcoded fallback if everything else fails
}

/**
 * Get current user data
 * 
 * @return array|null User data or null if not logged in
 */
function getCurrentUser()
{
    static $currentUserCache = null;

    if (!isUserLoggedIn()) {
        return null;
    }

    // Return cached version if already fetched in this request
    if ($currentUserCache !== null) {
        return $currentUserCache;
    }

    global $pdo;

    // Check database connection
    if (!isset($pdo) || $pdo === null) {
        error_log("Database connection not available in getCurrentUser");
        return null;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT 
                u.id, u.first_name, u.last_name, u.name, u.email, u.phone,
                u.profile_picture,
                u.delivery_location, u.street_location,
                u.location_lat, u.location_lng,
                u.google_id, u.auth_provider, u.profile_completed, u.is_verified, u.status, 
                u.branch_id,
                u.created_at, u.last_login_at,
                b.name as branch_name
            FROM users u
            LEFT JOIN branches b ON u.branch_id = b.id
            WHERE u.id = ? AND u.status = 'ACTIVE'
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $currentUserCache = $user ?: null;
        return $currentUserCache;
    } catch (PDOException $e) {
        error_log("Get user error: " . $e->getMessage());
        return null;
    }
}

/**
 * Check if current user is verified
 * 
 * @return bool True if verified, false otherwise
 */
function isUserVerified()
{
    $user = getCurrentUser();
    return $user && isset($user['is_verified']) && $user['is_verified'] == 1;
}

/**
 * Require user login (redirect if not logged in)
 * 
 * @param string|null $redirectUrl URL to redirect to after login
 * @return void
 */
function requireUserLogin($redirectUrl = null)
{
    // 1. Session check
    if (!isUserLoggedIn()) {
        $basePath = getBasePath();
        $next = $redirectUrl ?: ($_SERVER['REQUEST_URI'] ?? '/');

        // Ensure next URL includes base path if it's a relative path
        if (strpos($next, '/') === 0 && strpos($next, $basePath) !== 0 && $basePath !== '') {
            $next = $basePath . $next;
        }

        header('Location: ' . $basePath . '/auth/login.php?next=' . urlencode($next));
        exit;
    }

    // 2. Profile Check (Handle dismantled branches if user was tied only to one destroyed branch)
    $user = getCurrentUser();
    if (!$user) {
        // Clear session
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $basePath = getBasePath();
        header('Location: ' . $basePath . '/auth/signup.php?error=branch_dismantled');
        exit;
    }
}

/**
 * Require verified user (redirect if not verified)
 * 
 * @param string $message Message to show
 * @param string|null $redirectUrl URL to redirect back to after verification
 * @return void
 */
function requireVerifiedUser($message = 'Please verify your email address to continue.', $redirectUrl = null)
{
    requireUserLogin();

    if (!isUserVerified()) {
        $basePath = getBasePath();
        $_SESSION['verification_required_message'] = $message;

        // Use provided redirect URL or current page
        if ($redirectUrl === null) {
            $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
            // Ensure the redirect URL includes base path if needed
            if ($basePath && strpos($requestUri, $basePath) !== 0) {
                $redirectUrl = $basePath . $requestUri;
            } else {
                $redirectUrl = $requestUri;
            }
        }

        // Redirect to verification required page
        header('Location: ' . $basePath . '/auth/verification_required.php?message=' . urlencode($message) . '&redirect=' . urlencode($redirectUrl));
        exit;
    }
}

/**
 * Login user (create session)
 * 
 * @param int $userId User ID
 * @param string $email User email
 * @return bool True on success, false on failure
 */
function loginUser($userId, $email)
{
    if (empty($userId) || empty($email)) {
        error_log("❌ loginUser called with invalid parameters: userId={$userId}, email={$email}");
        return false;
    }

    initSecureSession();

    // Regenerate session ID on login for security
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int) $userId;
    $_SESSION['user_email'] = trim($email);
    $_SESSION['logged_in'] = true;

    // Fetch user's branch from DB and set it in session so the branch selector modal doesn't re-appear
    global $pdo;
    if (isset($pdo) && $pdo !== null) {
        try {
            // Update last login timestamp
            $stmt = $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?");
            $stmt->execute([$userId]);

            // Auto-set the customer branch from their profile
            $stmt = $pdo->prepare("SELECT branch_id FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $branchId = $stmt->fetchColumn();
            if ($branchId) {
                $_SESSION['customer_branch_id'] = (int)$branchId;
                error_log("✅ loginUser: Auto-selected branch {$branchId} for user {$userId}");
            }
        } catch (PDOException $e) {
            error_log("⚠️ loginUser: DB error during session config: " . $e->getMessage());
            // Don't fail login if updates fail
        }
    }

    return true;
}

/**
 * Logout user
 * 
 * @return void
 */
function logoutUser()
{
    initSecureSession();

    // Clear all session data
    $_SESSION = [];

    // Destroy session cookie
    if (isset($_COOKIE[session_name()])) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 3600,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    // Destroy session
    session_destroy();
}

/**
 * Find user by email
 * 
 * @param string $email User email
 * @return array|null User data or null if not found
 */
function findUserByEmail($email)
{
    if (empty($email)) {
        return null;
    }

    global $pdo;

    if (!isset($pdo) || $pdo === null) {
        error_log("❌ Database connection not available in findUserByEmail");
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([trim($email)]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    } catch (PDOException $e) {
        error_log("❌ Find user by email error: " . $e->getMessage());
        return null;
    }
}

/**
 * Find user by Google ID
 * 
 * @param string $googleId Google user ID
 * @return array|null User data or null if not found
 */
function findUserByGoogleId($googleId)
{
    if (empty($googleId)) {
        return null;
    }

    global $pdo;

    if (!isset($pdo) || $pdo === null) {
        error_log("❌ Database connection not available in findUserByGoogleId");
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = ? LIMIT 1");
        $stmt->execute([trim($googleId)]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    } catch (PDOException $e) {
        error_log("❌ Find user by Google ID error: " . $e->getMessage());
        return null;
    }
}

/**
 * Create new user
 * 
 * @param array $userData User data array containing:
 *   - first_name (string, optional)
 *   - last_name (string, optional)
 *   - name (string, optional - will be built from first_name + last_name if not provided)
 *   - email (string, required)
 *   - password_hash (string, optional - required for email auth)
 *   - google_id (string, optional - for Google OAuth)
 *   - phone (string, optional)
 *   - auth_provider (string, default: 'email')
 *   - profile_completed (int, default: 0)
 * @return array ['success' => bool, 'user_id' => int|null, 'error' => string|null]
 */
function createUser($userData)
{
    global $pdo;

    if (!isset($pdo) || $pdo === null) {
        error_log("❌ Database connection not available in createUser");
        return ['success' => false, 'user_id' => null, 'error' => 'Database connection error'];
    }

    // Validate required fields
    if (empty($userData['email'])) {
        return ['success' => false, 'user_id' => null, 'error' => 'Email is required'];
    }

    // Validate email format
    if (!filter_var($userData['email'], FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'user_id' => null, 'error' => 'Invalid email format'];
    }

    // Check if email already exists
    $existingUser = findUserByEmail($userData['email']);
    if ($existingUser) {
        // ALLOW UPGRADE: If user has no password (Google-only), allow setting password (merging signup)
        // DISABLED UPGRADE: The user requested "dont let that gmail from continue with google with same gmail to get regsited say use another gmail"
        // This implies NO merging of accounts even if one has no password.
        /*
        if (empty($existingUser['password_hash'])) {
            // ... Logic removed to enforce strict separation ...
        }
        */

        // IMPORTANT: DISABLED linking google id here by user choice. 
        // If email exists, we fail completely so they must use separate login methods

        if ($existingUser['auth_provider'] === 'google') {
            return ['success' => false, 'user_id' => null, 'error' => 'This email is registered with Google. Please log in using "Continue with Google".'];
        }

        if ($existingUser['auth_provider'] === 'google_email') {
            return ['success' => false, 'user_id' => null, 'error' => 'This email is already registered. Please log in with your email/password or Google.'];
        }

        return ['success' => false, 'user_id' => null, 'error' => 'This email or phone number is already registered. Please log in using your password.'];
    }

    try {
        // Build full name from first_name and last_name if not provided
        $fullName = $userData['name'] ?? null;
        if (empty($fullName) && isset($userData['first_name']) && isset($userData['last_name'])) {
            $fullName = trim(($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? ''));
            if (empty($fullName)) {
                $fullName = null;
            }
        }

        // Helper to detect optional columns
        $columnExists = function (string $columnName) use ($pdo): bool {
            try {
                $stmt = $pdo->prepare("SHOW COLUMNS FROM users LIKE ?");
                $stmt->execute([$columnName]);
                return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // If SHOW COLUMNS fails for any reason, assume column does not exist (fail-safe)
                error_log("⚠️ SHOW COLUMNS failed for '{$columnName}': " . $e->getMessage());
                return false;
            }
        };

        // Removed dynamic checks for locations

        // Build columns and values dynamically
        $columns = [
            'first_name',
            'last_name',
            'name',
            'email',
            'password_hash',
            'google_id',
            'phone',
            'delivery_location',
            'street_location',
            'location_lat',
            'location_lng',
            'branch_id'
        ];
        $values = [
            $userData['first_name'] ?? null,
            $userData['last_name'] ?? null,
            $fullName,
            trim($userData['email']),
            $userData['password_hash'] ?? null,
            $userData['google_id'] ?? null,
            $userData['phone'] ?? null,
            $userData['delivery_location'] ?? null,
            $userData['street_location'] ?? null,
            $userData['location_lat'] ?? null,
            $userData['location_lng'] ?? null,
            $userData['branch_id'] ?? 1
        ];

        // No location columns
        // Always include these with sensible defaults
        $columns[] = 'auth_provider';
        $values[] = $userData['auth_provider'] ?? 'email';
        $columns[] = 'profile_completed';
        $values[] = $userData['profile_completed'] ?? 0;
        $columns[] = 'is_verified';
        $values[] = 0;
        $columns[] = 'status';
        $values[] = 'ACTIVE';

        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $columnsSql = implode(', ', $columns);
        $sql = "INSERT INTO users ($columnsSql) VALUES ($placeholders)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);

        $userId = (int) $pdo->lastInsertId();

        if ($userId > 0) {
            error_log("✅ User created successfully: ID={$userId}, Email={$userData['email']}");
            return ['success' => true, 'user_id' => $userId, 'error' => null];
        } else {
            error_log("❌ Failed to get user ID after insert");
            return ['success' => false, 'user_id' => null, 'error' => 'Failed to create user account'];
        }

    } catch (PDOException $e) {
        error_log("❌ Create user error: " . $e->getMessage());
        error_log("❌ SQL Error Code: " . $e->getCode());

        // Handle duplicate entry error
        if ($e->getCode() == 23000) {
            return ['success' => false, 'user_id' => null, 'error' => 'Email or phone number already exists'];
        }

        return ['success' => false, 'user_id' => null, 'error' => 'Failed to create user account: ' . $e->getMessage()];
    }
}

/**
 * Link Google ID to existing user
 * 
 * @param int $userId User ID
 * @param string $googleId Google user ID
 * @return bool True on success, false on failure
 */
function linkGoogleIdToUser($userId, $googleId)
{
    if (empty($userId) || empty($googleId)) {
        return false;
    }

    global $pdo;

    if (!isset($pdo) || $pdo === null) {
        error_log("❌ Database connection not available in linkGoogleIdToUser");
        return false;
    }

    try {
        $stmt = $pdo->prepare("UPDATE users SET google_id = ?, auth_provider = 'google' WHERE id = ?");
        $result = $stmt->execute([trim($googleId), (int) $userId]);

        if ($result) {
            error_log("✅ Google ID linked to user ID: {$userId}");
        }

        return $result;
    } catch (PDOException $e) {
        error_log("❌ Link Google ID error: " . $e->getMessage());
        return false;
    }
}

/**
 * Verify email/password login
 * 
 * @param string $email User email
 * @param string $password User password
 * @return array ['success' => bool, 'user' => array|null, 'error' => string|null]
 */
function verifyEmailPasswordLogin($email, $password)
{
    global $pdo;

    if (!isset($pdo) || $pdo === null) {
        error_log("❌ Database connection not available in verifyEmailPasswordLogin");
        return ['success' => false, 'user' => null, 'error' => 'Database connection error'];
    }

    // Validate input
    if (empty($email) || empty($password)) {
        return ['success' => false, 'user' => null, 'error' => 'Email and password are required'];
    }

    // Check rate limit using email as identifier (not IP) - each user has their own limit
    $rateLimit = checkRateLimitForEmail('login_attempt', $email, 10, 900, 1800);
    if (!$rateLimit['allowed']) {
        $message = 'Too many login attempts. Please try again later.';
        if (isset($rateLimit['locked_until'])) {
            $minutes = ceil((strtotime($rateLimit['locked_until']) - time()) / 60);
            $message = "Too many login attempts. Please try again in {$minutes} minutes.";
        }
        return [
            'success' => false,
            'user' => null,
            'error' => $message
        ];
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND status = 'ACTIVE' LIMIT 1");
        $stmt->execute([trim($email)]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            // Don't reveal if email exists (security best practice)
            return ['success' => false, 'user' => null, 'error' => 'Invalid email or password'];
        }

        // Check if user has a password (not Google-only account)
        if (empty($user['password_hash'])) {
            return [
                'success' => false,
                'user' => null,
                'error' => 'This account uses Google sign-in. Please sign in with Google.'
            ];
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'user' => null, 'error' => 'Invalid email or password'];
        }

        // IMPORTANT: Check if email is verified - users MUST verify email before login
        /*  <-- DISABLED BY USER REQUEST: Allow login even if not verified
        if (empty($user['is_verified']) || $user['is_verified'] != 1) {
            return [
                'success' => false,
                'user' => null,
                'error' => 'Please verify your email address before logging in. Check your inbox for the verification code.'
            ];
        }
        */

        // Reset rate limit on successful login
        resetRateLimit('login_attempt');

        error_log("✅ Login successful for email: {$email}");
        return ['success' => true, 'user' => $user, 'error' => null];

    } catch (PDOException $e) {
        error_log("❌ Email/password login error: " . $e->getMessage());
        return ['success' => false, 'user' => null, 'error' => 'Database error occurred'];
    }
}
