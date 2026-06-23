<?php
/**
 * User Login Page - Modern & Responsive
 * Simple login with email/password + Google sign-in
 */

// Security initialization (must be first)
require_once __DIR__ . '/../app/functions/security_init.php';

require_once __DIR__ . '/../app/functions/auth.php';

// Get base path for URLs
$basePath = getBasePath();

// Get base path for URLs
$basePath = getBasePath();

// Redirect if already logged in
if (isUserLoggedIn()) {
    $next = $_GET['next'] ?? $basePath . '/';
    if (strpos($next, '/') === 0 && strpos($next, $basePath) !== 0) {
        $next = $basePath . $next;
    }
    header('Location: ' . $next);
    exit;
}

$error = '';
$success = '';

// Get error from URL (from OAuth callback or other redirects)
if (isset($_GET['error'])) {
    $error = $_GET['error'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    error_log("DEBUG_LOGIN (auth/login.php): Form submitted. Email: " . ($_POST['email'] ?? ''));
    require_once __DIR__ . '/../app/handlers/login.php';
    $result = handleLogin();

    if ($result['success']) {
        error_log("DEBUG_LOGIN (auth/login.php): Login successful. Calling loginUser for ID " . $result['user']['id']);
        loginUser($result['user']['id'], $result['user']['email']);
        error_log("DEBUG_LOGIN (auth/login.php): After loginUser. Session user_id is: " . ($_SESSION['user_id'] ?? 'MISSING'));
        $next = $_GET['next'] ?? $basePath . '/';
        // Ensure next URL includes base path if it's a relative path
        if (strpos($next, '/') === 0 && strpos($next, $basePath) !== 0 && $basePath !== '') {
            $next = $basePath . $next;
        }
        error_log("DEBUG_LOGIN (auth/login.php): Redirecting to: " . $next);
        session_write_close();
        header('Location: ' . $next);
        exit;
    } else {
        error_log("DEBUG_LOGIN (auth/login.php): Login failed - " . $result['error']);
        $error = $result['error'];
    }
}

// Check for messages
if (isset($_GET['logout'])) {
    $success = 'You have been successfully logged out';
}
if (isset($_GET['registered'])) {
    $success = 'Registration successful! Please check your email to verify your account.';
}
if (isset($_GET['verified'])) {
    $success = 'Email verified successfully! You can now log in.';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#667eea">
    <title>Login - JustKleek</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link href="https://fonts.bunny.net/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #667eea;
            --primary-dark: #5568d3;
            --primary-light: #818cf8;
            --secondary: #764ba2;
            --accent: #f093fb;
            --text-dark: #1a202c;
            --text-gray: #4a5568;
            --text-light: #718096;
            --border: #e2e8f0;
            --bg-white: #ffffff;
            --bg-gray: #f7fafc;
            --error: #e53e3e;
            --success: #38a169;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.15);
            --shadow-xl: 0 20px 40px rgba(0, 0, 0, 0.2);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
            position: relative;
            overflow-x: hidden;
        }

        /* Animated background elements */
        body::before {
            content: '';
            position: fixed;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: float 20s infinite linear;
            z-index: 0;
        }

        @keyframes float {
            0% {
                transform: translate(0, 0) rotate(0deg);
            }

            100% {
                transform: translate(50px, 50px) rotate(360deg);
            }
        }

        .auth-container {
            width: 100%;
            max-width: 440px;
            position: relative;
            z-index: 1;
        }

        .auth-box {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 24px;
            box-shadow: var(--shadow-xl);
            padding: 48px 40px;
            width: 100%;
            animation: slideUp 0.6s ease-out;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .auth-logo {
            text-align: center;
            margin-bottom: 32px;
            animation: fadeIn 0.8s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .auth-logo-img {
            max-width: 140px;
            height: auto;
            filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
            transition: transform 0.3s ease;
        }

        .auth-logo-img:hover {
            transform: scale(1.05);
        }

        .auth-title {
            font-size: 32px;
            font-weight: 800;
            color: var(--text-dark);
            margin-bottom: 8px;
            text-align: center;
            letter-spacing: -0.5px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .auth-subtitle {
            color: var(--text-light);
            text-align: center;
            margin-bottom: 36px;
            font-size: 15px;
            font-weight: 400;
            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 24px;
            animation: fadeInUp 0.6s ease-out;
            animation-fill-mode: both;
        }

        .form-group:nth-child(1) {
            animation-delay: 0.1s;
        }

        .form-group:nth-child(2) {
            animation-delay: 0.2s;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .form-label {
            display: block;
            margin-bottom: 10px;
            color: var(--text-dark);
            font-weight: 600;
            font-size: 14px;
            letter-spacing: 0.2px;
        }

        .form-input {
            width: 100%;
            padding: 16px 20px;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 16px;
            font-weight: 400;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-sizing: border-box;
            background: var(--bg-white);
            color: var(--text-dark);
            font-family: inherit;
        }

        /* Override any global button styles */
        .form-group button,
        .password-input-wrapper button {
            appearance: none !important;
            -webkit-appearance: none !important;
            -moz-appearance: none !important;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: translateY(-1px);
        }

        .form-input::placeholder {
            color: var(--text-light);
        }

        /* Password Input with Toggle */
        .password-input-wrapper {
            position: relative !important;
            width: 100% !important;
            display: block !important;
        }

        .password-input {
            padding-right: 50px !important;
        }

        .password-toggle {
            position: absolute !important;
            right: 16px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            background: transparent !important;
            border: none !important;
            cursor: pointer !important;
            padding: 8px !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            color: var(--text-gray) !important;
            transition: all 0.3s ease !important;
            z-index: 100 !important;
            width: 36px !important;
            height: 36px !important;
            border-radius: 6px !important;
            margin: 0 !important;
            box-shadow: none !important;
            opacity: 1 !important;
            visibility: visible !important;
        }

        .password-toggle:hover {
            color: var(--primary) !important;
            background: rgba(102, 126, 234, 0.1) !important;
        }

        .password-toggle:focus {
            outline: none !important;
            color: var(--primary) !important;
            background: rgba(102, 126, 234, 0.1) !important;
        }

        .password-toggle:active {
            transform: translateY(-50%) scale(0.95) !important;
        }

        .eye-icon {
            width: 20px !important;
            height: 20px !important;
            transition: opacity 0.2s ease !important;
            display: block !important;
            flex-shrink: 0 !important;
            opacity: 1 !important;
            visibility: visible !important;
        }

        .eye-closed {
            display: none !important;
        }

        .password-input-wrapper.show-password .eye-open {
            display: none !important;
        }

        .password-input-wrapper.show-password .eye-closed {
            display: block !important;
        }

        .password-input-wrapper:not(.show-password) .eye-closed {
            display: none !important;
        }

        .password-input-wrapper:not(.show-password) .eye-open {
            display: block !important;
        }

        /* Back to Home Button */
        .btn-back-home {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 28px;
            background: linear-gradient(135deg, #FFA53B 0%, #ff8c1a 100%);
            color: white;
            border: none;
            border-radius: 16px;
            text-decoration: none;
            font-family: 'Inter', sans-serif;
            font-size: 15px;
            font-weight: 700;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            white-space: nowrap;
            box-shadow: 0 6px 20px rgba(255, 165, 59, 0.35);
            position: relative;
            overflow: hidden;
            letter-spacing: 0.3px;
            margin-top: 24px;
            width: 100%;
            justify-content: center;
        }

        .btn-back-home::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .btn-back-home:hover::before {
            left: 100%;
        }

        .btn-back-home:hover {
            background: linear-gradient(135deg, #ff8c1a 0%, #FFA53B 100%);
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(255, 165, 59, 0.5);
        }

        .btn-back-home:active {
            transform: translateY(-1px);
        }

        .btn-back-home svg {
            transition: transform 0.3s ease;
            stroke-width: 2.5;
        }

        .btn-back-home:hover svg {
            transform: translateX(-4px);
        }

        .btn-primary {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            margin-bottom: 20px;
            box-shadow: var(--shadow-md);
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 100%);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-google {
            width: 100%;
            padding: 16px;
            background: var(--bg-white);
            color: var(--text-dark);
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-sm);
            font-family: inherit;
            text-decoration: none;
        }

        .btn-google:hover {
            border-color: #db4437;
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-google svg {
            width: 22px;
            height: 22px;
            flex-shrink: 0;
        }

        .divider {
            text-align: center;
            margin: 32px 0;
            position: relative;
        }

        .divider::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            width: 100%;
            height: 1px;
            background: linear-gradient(to right, transparent, var(--border), transparent);
        }

        .divider span {
            background: rgba(255, 255, 255, 0.98);
            padding: 0 20px;
            color: var(--text-light);
            position: relative;
            font-size: 13px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .auth-link {
            text-align: center;
            margin-top: 28px;
            color: var(--text-gray);
            font-size: 14px;
        }

        .auth-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
            position: relative;
        }

        .auth-link a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--primary);
            transition: width 0.3s;
        }

        .auth-link a:hover::after {
            width: 100%;
        }

        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
            animation: slideDown 0.4s ease-out;
            border-left: 4px solid;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-error {
            background: #fff5f5;
            color: var(--error);
            border-color: var(--error);
        }

        .alert-success {
            background: #f0fff4;
            color: var(--success);
            border-color: var(--success);
        }

        /* Mobile Responsive */
        @media (max-width: 640px) {
            body {
                padding: 12px;
            }

            .auth-box {
                padding: 36px 24px;
                border-radius: 20px;
            }

            .auth-logo-img {
                max-width: 110px;
            }

            .auth-title {
                font-size: 26px;
            }

            .auth-subtitle {
                font-size: 14px;
                margin-bottom: 28px;
            }

            .form-group {
                margin-bottom: 20px;
            }

            .form-input {
                padding: 14px 16px;
                font-size: 16px;
            }

            .btn-primary,
            .btn-google {
                padding: 16px;
                font-size: 15px;
            }

            .divider {
                margin: 24px 0;
            }
        }

        @media (max-width: 480px) {
            .auth-box {
                padding: 32px 20px;
            }

            .auth-title {
                font-size: 24px;
            }

            .form-input {
                padding: 12px 14px;
            }

            .password-input {
                padding-right: 45px;
            }

            .password-toggle {
                right: 12px;
                padding: 6px;
            }

            .btn-back-home {
                padding: 12px 24px;
                font-size: 14px;
            }
        }
    </style>
    <script>
        // Password Toggle Functionality - Show/Hide Password
        document.addEventListener('DOMContentLoaded', function () {
            const passwordToggle = document.getElementById('passwordToggle');
            const passwordInput = document.getElementById('password');
            const passwordWrapper = passwordInput?.closest('.password-input-wrapper');

            if (passwordToggle && passwordInput && passwordWrapper) {
                // Toggle password visibility
                passwordToggle.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();

                    const isPassword = passwordInput.type === 'password';

                    // Toggle input type
                    passwordInput.type = isPassword ? 'text' : 'password';

                    // Toggle wrapper class for icon switching
                    if (isPassword) {
                        passwordWrapper.classList.add('show-password');
                        passwordToggle.setAttribute('aria-label', 'Hide password');
                    } else {
                        passwordWrapper.classList.remove('show-password');
                        passwordToggle.setAttribute('aria-label', 'Show password');
                    }
                });

                // Prevent form submission when clicking toggle
                passwordToggle.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                });
            }
        });
    </script>
    <script src="<?php echo $basePath; ?>/js/prevent-zoom.js?v=1.0.1"></script>
    <?php require_once __DIR__ . '/../includes/meta_pixel.php'; ?>
</head>

<body>
    <div class="auth-container">
        <div class="auth-box">
            <div class="auth-logo">
                <img src="<?php echo $basePath; ?>/assets/logo.png" alt="JustKleek" class="auth-logo-img"
                    onerror="this.style.display='none';">
            </div>
            <h1 class="auth-title">Welcome Back</h1>
            <p class="auth-subtitle">Sign in to your account to continue</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo e($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo e($success); ?></div>
            <?php endif; ?>

            <a href="<?php echo $basePath; ?>/auth/google_start.php?next=<?php echo urlencode($_GET['next'] ?? '/'); ?>"
                class="btn-google">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path fill="#4285F4"
                        d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" />
                    <path fill="#34A853"
                        d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" />
                    <path fill="#FBBC05"
                        d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" />
                    <path fill="#EA4335"
                        d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" />
                </svg>
                Continue with Google
            </a>

            <div class="divider">
                <span>or</span>
            </div>

            <form method="POST" action="" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                <div class="form-group">
                    <label class="form-label" for="email">Email Address</label>
                    <input type="email" id="email" name="email" class="form-input" placeholder="Enter your email"
                        required autofocus>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" id="password" name="password" class="form-input password-input"
                            placeholder="Enter your password" required>
                        <button type="button" class="password-toggle" id="passwordToggle"
                            aria-label="Toggle password visibility">
                            <svg class="eye-icon eye-open" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                xmlns="http://www.w3.org/2000/svg">
                                <path d="M1 12C1 12 5 4 12 4C19 4 23 12 23 12C23 12 19 20 12 20C5 20 1 12 1 12Z"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round" />
                                <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                            <svg class="eye-icon eye-closed" width="20" height="20" viewBox="0 0 24 24" fill="none"
                                xmlns="http://www.w3.org/2000/svg" style="display: none;">
                                <path
                                    d="M17.94 17.94C16.2306 19.243 14.1491 19.9649 12 20C5 20 1 12 1 12C2.24389 9.68192 3.96914 7.65663 6.06 6.06M9.9 4.24C10.5883 4.0789 11.2931 3.99836 12 4C19 4 23 12 23 12C22.393 13.1356 21.6691 14.2048 20.84 15.19M14.12 14.12C13.8454 14.4148 13.5141 14.6512 13.1462 14.8151C12.7782 14.9791 12.3809 15.0673 11.9781 15.0744C11.5753 15.0815 11.1751 15.0074 10.8016 14.8565C10.4281 14.7056 10.0887 14.4811 9.80385 14.1962C9.51897 13.9113 9.29439 13.5719 9.14351 13.1984C8.99262 12.8249 8.91853 12.4247 8.92563 12.0219C8.93274 11.6191 9.02091 11.2218 9.18488 10.8538C9.34884 10.4859 9.58525 10.1546 9.88 9.88"
                                    stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round" />
                                <path d="M1 1L23 23" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round" />
                            </svg>
                        </button>
                    </div>
                    <div style="text-align: right; margin-top: 8px;">
                        <a href="<?php echo $basePath; ?>/auth/forgot_password.php"
                            style="color: var(--primary); text-decoration: none; font-size: 13px; font-weight: 500;">Forgot
                            Password?</a>
                    </div>
                </div>

                <button type="submit" name="login" class="btn-primary">Sign In</button>
            </form>

            <div class="auth-link">
                Don't have an account? <a
                    href="<?php echo $basePath; ?>/auth/signup.php?next=<?php echo urlencode($_GET['next'] ?? '/'); ?>">Sign
                    up</a>
            </div>

            <a href="<?php echo $basePath; ?>/" class="btn-back-home">
                <svg width="18" height="18" viewBox="0 0 18 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M11.25 13.5L6.75 9L11.25 4.5" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round" />
                </svg>
                <span>Back to Home</span>
            </a>
        </div>
    </div>
</body>

</html>
