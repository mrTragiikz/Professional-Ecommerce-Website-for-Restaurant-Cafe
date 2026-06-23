<?php
if (!defined('RiderAppCore')) {
    define('RiderAppCore', true);
}
// Initialize isolated rider session (JK_RIDER_SESS, 7-day lifetime)
require_once __DIR__ . '/_session_init.php';
require_once __DIR__ . '/../../config/db.php';

// For the login UI we only need to know that the `riders` table exists.
// All authentication is done against `riders` in authenticate.php.
$schema = null;
$tableError = false;
$error = null;
try {
    $stmt = $pdo->query("SELECT 1 FROM riders LIMIT 1");
    if ($stmt) {
        $cols = $pdo->query("SHOW COLUMNS FROM riders")->fetchAll(PDO::FETCH_COLUMN);
        $schema = [
            'table' => 'riders',
            'id' => 'id',
            'name' => in_array('full_name', $cols) ? 'full_name' : (in_array('name', $cols) ? 'name' : 'username'),
            'cols' => $cols,
        ];
    }
} catch (Throwable $e) {
    $tableError = true;

    // HANDLE AUTO-SETUP REQUEST (if triggered by a super-admin)
    if (isset($_POST['auto_setup']) && $_POST['auto_setup'] === 'create_tables') {
        try {
            // Create riders table
            $pdo->exec("CREATE TABLE IF NOT EXISTS `riders` (
                `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
                `branch_id` int UNSIGNED DEFAULT NULL,
                `can_take_available` tinyint(1) NOT NULL DEFAULT '1',
                `username` varchar(100) NOT NULL,
                `phone` varchar(20) NOT NULL,
                `password_hash` varchar(255) NOT NULL,
                `status` enum('active','inactive') DEFAULT 'active',
                `location_lat` decimal(10,8) DEFAULT NULL,
                `location_lng` decimal(11,8) DEFAULT NULL,
                `last_seen` timestamp NULL DEFAULT NULL,
                `closing_start_km` decimal(10,2) DEFAULT NULL,
                `duty_start_time` datetime DEFAULT NULL,
                `closing_end_km` decimal(10,2) DEFAULT NULL,
                `duty_end_time` datetime DEFAULT NULL,
                `closing_total_km` decimal(10,2) DEFAULT NULL,
                `closing_audit_date` date DEFAULT NULL,
                `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `phone` (`phone`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Create rider_audit_logs table
            $pdo->exec("CREATE TABLE IF NOT EXISTS `rider_audit_logs` (
                `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
                `rider_id` int UNSIGNED NOT NULL,
                `action` varchar(64) NOT NULL,
                `description` text,
                `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_rider_time` (`rider_id`,`created_at`),
                KEY `created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Create rider_daily_closings table
            $pdo->exec("CREATE TABLE IF NOT EXISTS `rider_daily_closings` (
                `id` int UNSIGNED NOT NULL AUTO_INCREMENT,
                `rider_id` int UNSIGNED NOT NULL,
                `closing_date` date NOT NULL,
                `start_km` decimal(10,2) DEFAULT NULL,
                `duty_start_time` datetime DEFAULT NULL,
                `end_km` decimal(10,2) DEFAULT NULL,
                `duty_end_time` datetime DEFAULT NULL,
                `total_km` decimal(10,2) DEFAULT NULL,
                `hired_km` decimal(10,3) NOT NULL DEFAULT '0.000',
                `vacant_km` decimal(10,3) NOT NULL DEFAULT '0.000',
                `orders_count` int UNSIGNED NOT NULL DEFAULT '0',
                `delivery_revenue` decimal(10,2) NOT NULL DEFAULT '0.00',
                `cash_collect` decimal(10,2) NOT NULL DEFAULT '0.00',
                `online_collect` decimal(10,2) NOT NULL DEFAULT '0.00',
                `is_locked` tinyint(1) DEFAULT '0',
                `locked_at` datetime DEFAULT NULL,
                `status` varchar(20) DEFAULT 'OPEN',
                `finalized_at` datetime DEFAULT NULL,
                `created_by_rider_id` int UNSIGNED DEFAULT NULL,
                `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_rider_date` (`rider_id`,`closing_date`),
                KEY `closing_date` (`closing_date`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            header("Location: login.php?setup=success");
            exit;
        } catch (Throwable $setupError) {
            $error = "Auto-setup failed: " . $setupError->getMessage();
        }
    }
}

if (empty($_SESSION['rider_csrf'])) {
    try {
        $_SESSION['rider_csrf'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['rider_csrf'] = md5(uniqid(mt_rand(), true));
    }
}

// Fetch active branches for the login selector
$branches = [];
try {
    $branches = $pdo->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Fallback if branches table is empty or missing
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0" />
    <title>Sign in - Rider Account</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="google_login.css?v=<?php echo time(); ?>">
</head>

<body>
    <div class="g-login-wrapper">
        <div class="g-login-card">
            <div class="g-logo">
                <img src="../../assets/logo.png" alt="JustKleek"
                    onerror="this.src='https://www.gstatic.com/images/branding/googlelogo/2x/googlelogo_color_92x30dp.png'">
            </div>
            <h1 class="g-title">Sign in</h1>
            <p class="g-subtitle">Use your Rider account</p>

            <?php if (!$schema): ?>
                <div class="g-modal-overlay">
                    <div class="g-modal-card">
                        <div class="g-modal-icon">⚙️</div>
                        <h2 class="g-modal-title">System Error</h2>
                        <p class="g-modal-text">Rider configuration not found. Please contact your administrator.</p>
                    </div>
                </div>
            <?php else: ?>
                <?php if (isset($_SESSION['rider_error']) || (isset($_GET['error']) && $_GET['error'] === 'account_deactivated')):
                    $errorMsg = $_SESSION['rider_error'] ?? 'Your account has been deactivated by the administrator.';
                    ?>
                    <div id="errorModal" class="g-modal-overlay">
                        <div class="g-modal-card">
                            <div class="g-modal-icon">⚠️</div>
                            <h2 class="g-modal-title">Sign-in failed</h2>
                            <p class="g-modal-text"><?php echo htmlspecialchars($errorMsg); ?></p>
                            <button type="button" class="g-btn g-modal-btn" onclick="closeModal()">Try again</button>
                        </div>
                    </div>
                    <?php unset($_SESSION['rider_error']); ?>
                <?php endif; ?>

                <form action="authenticate.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['rider_csrf']); ?>">

                    <div class="g-form-group">
                        <input type="text" name="identifier" class="g-input" id="identifier" required
                            autocomplete="username" placeholder=" ">
                        <label for="identifier" class="g-label">Email or phone</label>
                    </div>

                    <div class="g-form-group">
                        <input type="password" name="password" class="g-input" id="password" required
                            autocomplete="current-password" placeholder=" ">
                        <label for="password" class="g-label">Enter your password</label>
                        <span class="password-toggle" onclick="togglePassword()">
                            <svg id="eye-icon" viewBox="0 0 24 24" width="24" height="24" fill="currentColor">
                                <path
                                    d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z" />
                            </svg>
                        </span>
                    </div>

                    <div class="g-form-group">
                        <select name="branch_id" class="g-input g-select" id="branch_id" required>
                            <option value="" disabled selected hidden></option>
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?php echo $branch['id']; ?>"><?php echo htmlspecialchars($branch['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="branch_id" class="g-label">Select Branch</label>
                    </div>

                    <div class="g-actions" style="justify-content: center; margin-top: 32px;">
                        <button type="submit" class="g-btn"
                            style="width: 100%; height: 48px; border-radius: 24px; font-size: 16px;">Login</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <?php if ($tableError): ?>
    <!-- Falls back to a system-wide notice if even the basic DB query fails -->
    <div style="background: #ffffff; border-radius: 16px; border: 1px solid #fee2e2; padding: 24px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); margin-bottom: 24px; transform: translateY(0); animation: slideIn 0.5s ease-out;">
        <div style="display: flex; align-items: flex-start; gap: 16px;">
            <div style="background: #fee2e2; border-radius: 50%; width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            </div>
            <div style="flex: 1;">
                <h4 style="margin: 0 0 6px 0; color: #991b1b; font-size: 15px; font-weight: 800;">System Setup Required</h4>
                <p style="margin: 0; color: #64748b; font-size: 13px; line-height: 1.5;">The required rider tables are missing from your database. The application cannot proceed without them.</p>
                
                <form method="POST" style="margin-top: 16px;">
                    <button type="submit" name="auto_setup" value="create_tables" style="background: #1e293b; color: #fff; border: none; padding: 10px 20px; border-radius: 10px; font-size: 13px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83M2 12h4m12 0h4m-12 7.07l2.83-2.83m8.48-8.48l2.83-2.83"></path></svg>
                        Run Auto-Setup Now
                    </button>
                    <p style="margin: 8px 0 0 0; font-size: 11px; color: #94a3b8;">* Only run this if you are a system administrator.</p>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (isset($_GET['setup']) && $_GET['setup'] === 'success'): ?>
    <div style="background: #f0fdf4; border-radius: 16px; border: 1px solid #dcfce7; padding: 16px; margin-bottom: 24px;">
        <p style="margin: 0; color: #166534; font-size: 13px; font-weight: 700; text-align: center;">✓ Setup Complete! Tables created successfully. You can now login.</p>
    </div>
<?php endif; ?>

        <script>
            function togglePassword() {
                const passwordInput = document.getElementById('password');
                const eyeIcon = document.getElementById('eye-icon');
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    eyeIcon.innerHTML = '<path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.44-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/>';
                } else {
                    passwordInput.type = 'password';
                    eyeIcon.innerHTML = '<path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>';
                }
            }
            function closeModal() {
                const modal = document.getElementById('errorModal');
                if (modal) modal.style.display = 'none';
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        </script>

        <div class="g-footer">
            <div class="g-language">Software by <strong>Prabin Sharma</strong></div>
            <div class="g-footer-links">
                <a href="mailto:sharmaprabin160@gmail.com">info - sharmaprabin160@gmail.com</a>
            </div>
        </div>
    </div>
</body>

</html>