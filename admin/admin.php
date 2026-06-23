<?php
/**
 * Admin Login Page
 */

// Display errors.
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Error handling.
$initError = '';
$error = '';
$success = '';

// Custom error handler.
set_error_handler(function ($errno, $errstr, $errfile, $errline) use (&$initError) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    $initError = "Error [$errno]: $errstr in $errfile on line $errline";
    error_log("Admin login error: $initError");
    return true;
});

// Session check.
if (session_status() === PHP_SESSION_NONE) {
    // Load centralized config and start admin session (JK_ADMIN_SESS, 7-day lifetime)
    if (!defined('JK_SESSION_NAME_ADMIN')) {
        require_once __DIR__ . '/../config/session_config.php';
    }
    jk_start_admin_session();
}

// Load auth.
try {
    $authFile = __DIR__ . '/includes/auth.php';
    if (!file_exists($authFile)) {
        throw new Exception('Authentication file not found: includes/auth.php');
    }

    // Catch output.
    ob_start();
    $authLoaded = @include_once $authFile;
    $output = ob_get_clean();

    if ($authLoaded === false) {
        throw new Exception('Failed to load authentication file. ' . ($output ?: 'Check file permissions.'));
    }

    if (!empty($output)) {
        error_log('Auth file output: ' . $output);
    }

    // Function check.
    if (!function_exists('isAdminLoggedIn')) {
        throw new Exception('Required function isAdminLoggedIn() not found. Auth file may not have loaded correctly.');
    }

    // Check login.
    if (isAdminLoggedIn()) {
        header('Location: admin_dashboard.php');
        exit;
    }

} catch (Throwable $e) {
    $initError = 'Initialization Error: ' . htmlspecialchars($e->getMessage());
    error_log('Admin login initialization error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
} catch (Exception $e) {
    $initError = 'Initialization Error: ' . htmlspecialchars($e->getMessage());
    error_log('Admin login initialization error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
}

// Restore error handler
restore_error_handler();

// Login logic.
if (empty($initError) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        if (!function_exists('verifyAdminLogin')) {
            $error = 'Authentication system not available. Please check server configuration.';
        } else {
            try {
                $result = verifyAdminLogin($username, $password);

                if ($result['success']) {
                    session_regenerate_id(true);
                    $_SESSION['admin_id'] = $result['admin_id'];
                    $_SESSION['admin_username'] = $result['username'];
                    $_SESSION['admin_role'] = $result['role'];
                    $_SESSION['admin_branch_id'] = $result['branch_id'];
                    header('Location: admin_dashboard.php');
                    exit;
                } else {
                    $error = $result['error'];
                }
            } catch (Exception $e) {
                $error = 'Login error: ' . htmlspecialchars($e->getMessage());
                error_log('Login error: ' . $e->getMessage());
            } catch (Error $e) {
                $error = 'Fatal login error: ' . htmlspecialchars($e->getMessage());
                error_log('Fatal error: ' . $e->getMessage());
            }
        }
    }
}

// Check for logout message
if (isset($_GET['logout'])) {
    $success = 'You have been successfully logged out';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Admin Login - JustKleek</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link href="https://fonts.bunny.net/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body.login-page {
            background-color: #f0f4f9;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 24px;
            font-family: 'Roboto', -apple-system, sans-serif;
            color: #1f1f1f;
        }

        .login-container {
            max-width: 1040px;
            width: 100%;
            animation: fadeIn 0.4s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-card {
            display: flex;
            flex-direction: column;
            background: #ffffff;
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
            min-height: 480px;
        }

        @media (min-width: 768px) {
            .login-card {
                flex-direction: row;
            }
        }

        .login-left {
            flex: 1;
            padding: 48px 40px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .login-right {
            flex: 1;
            padding: 48px 40px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .g-logo {
            display: flex;
            align-items: center;
            font-size: 24px;
            font-weight: 500;
            color: #1f1f1f;
            margin-bottom: 24px;
            letter-spacing: -0.5px;
        }

        .g-logo svg {
            margin-right: 12px;
            color: #0b57d0;
        }

        .g-title {
            font-size: 36px;
            font-weight: 400;
            color: #1f1f1f;
            margin-bottom: 16px;
            line-height: 1.2;
        }

        .g-subtitle {
            font-size: 16px;
            color: #444746;
            margin-bottom: 32px;
            line-height: 1.5;
        }

        .g-input-group {
            position: relative;
            margin-bottom: 12px;
        }

        .g-input {
            width: 100%;
            padding: 16px 14px;
            font-size: 16px;
            color: #1f1f1f;
            border: 1px solid #747775;
            border-radius: 4px;
            background: transparent;
            outline: none;
            transition: all 0.2s;
        }

        .g-input:focus {
            border-color: #0b57d0;
            border-width: 2px;
            padding: 15px 13px;
            /* visual bump offset */
        }

        .g-label {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: #fff;
            padding: 0 4px;
            color: #444746;
            font-size: 16px;
            transition: all 0.15s ease-out;
            pointer-events: none;
        }

        .g-input:focus~.g-label,
        .g-input:not(:placeholder-shown)~.g-label {
            top: 0;
            font-size: 12px;
            color: #0b57d0;
        }

        .g-input:not(:focus):not(:placeholder-shown)~.g-label {
            color: #444746;
        }

        .g-input[type="password"],
        .g-input.has-toggle {
            padding-right: 48px;
        }

        .g-password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #444746;
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background 0.2s;
        }

        .g-password-toggle:hover {
            color: #1f1f1f;
            background: #f0f4f9;
        }

        .g-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 36px;
        }

        .g-btn-primary {
            background: #0b57d0;
            color: #ffffff;
            border: none;
            border-radius: 24px;
            padding: 10px 24px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s, box-shadow 0.2s;
        }

        .g-btn-primary:active {
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.2);
        }

        .g-btn-primary:hover {
            background: #0842a0;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
        }

        .g-btn-primary:disabled {
            background: #1f1f1f1f;
            color: #1f1f1f61;
            cursor: not-allowed;
            box-shadow: none;
        }

        .alert-g {
            background: #ffebe9;
            color: #d12215;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            border: 1px solid #fecaca;
        }

        .alert-success-g {
            background: #e6f4ea;
            color: #137333;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 24px;
            border: 1px solid #bbf7d0;
        }

        .g-forgot {
            color: #0b57d0;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            border-radius: 4px;
            padding: 4px;
            margin-left: -4px;
        }

        .g-forgot:hover {
            background: #f0f4f9;
        }



        .future-login-hint {
            font-size: 14px;
            color: #747775;
            margin-top: auto;
            padding-top: 48px;
            font-weight: 500;
        }
    </style>
</head>

<body class="login-page">
    <div class="login-container">
        <div class="login-card">

            <!-- Left Side / Branding -->
            <div class="login-left">
                <div>
                    <div class="g-logo">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 2L2 7L12 12L22 7L12 2Z" fill="currentColor" />
                            <path d="M2 17L12 22L22 17" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" />
                            <path d="M2 12L12 17L22 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" />
                        </svg>
                        <span id="logoText">JustKleek Admin</span>
                    </div>
                    <h1 class="g-title" id="pageTitle">Sign in</h1>
                    <p class="g-subtitle" id="pageSubtitle">Use your Admin Account to continue to the JustKleek
                        Dashboard securely.</p>
                </div>

                <div class="future-login-hint">
                    For other ways to sign in, check back later.
                </div>
            </div>

            <!-- Right Side / Form -->
            <div class="login-right">
                <?php if ($initError): ?>
                    <div class="alert-g">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            style="flex-shrink:0; margin-top:2px;">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                        </svg>
                        <div>
                            <strong>System Error</strong><br>
                            <span><?php echo $initError; ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert-g">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            style="flex-shrink:0; margin-top:2px;">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="8" x2="12" y2="12"></line>
                            <line x1="12" y1="16" x2="12.01" y2="16"></line>
                        </svg>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert-success-g">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" <?php echo $initError ? 'style="opacity: 0.6; pointer-events: none;"' : ''; ?>>

                    <div style="margin-bottom: 24px;"> <!-- wrapping inputs -->
                        <div class="g-input-group">
                            <input type="text" id="username" name="username" class="g-input" placeholder=" " required
                                <?php echo $initError ? 'disabled' : 'autofocus'; ?>>
                            <label for="username" class="g-label">Username</label>
                        </div>

                        <div class="g-input-group">
                            <input type="password" id="password" name="password" class="g-input has-toggle"
                                placeholder=" " required <?php echo $initError ? 'disabled' : ''; ?>>
                            <label for="password" class="g-label">Password</label>
                            <button type="button" class="g-password-toggle" onclick="togglePassword()"
                                title="Show password" <?php echo $initError ? 'disabled' : ''; ?>>
                                <svg id="eye-icon" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="g-actions">
                        <a href="../index.php" class="g-forgot">Back to Home</a>
                        <button type="submit" name="login" class="g-btn-primary" <?php echo $initError ? 'disabled' : ''; ?>>
                            Next
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>

    <script>
        function togglePassword() {
            const passInput = document.getElementById("password");
            const eyeIcon = document.getElementById("eye-icon");
            if (passInput.type === "password") {
                passInput.type = "text";
                eyeIcon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
            } else {
                passInput.type = "password";
                eyeIcon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
            }
        }

    </script>
</body>

</html>