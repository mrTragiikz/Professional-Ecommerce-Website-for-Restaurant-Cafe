<?php
/**
 * User Signup Page - Modern & Responsive
 * Signup with first name, last name, phone, email, password, confirm password
 */

// Security initialization (must be first)
require_once __DIR__ . '/../app/functions/security_init.php';

require_once __DIR__ . '/../app/functions/auth.php';

// Get base path for URLs
$basePath = getBasePath();

// Redirect if already logged in
if (isUserLoggedIn()) {
    $next = $_GET['next'] ?? '/';
    header('Location: ' . $next);
    exit;
}

$error = '';
if (isset($_GET['error']) && $_GET['error'] === 'branch_dismantled') {
    $error = 'Your previous branch has been dismantled. Please register again to continue.';
}
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signup'])) {
    error_log("DEBUG_LOGIN (auth/signup.php): Form submitted. Email: " . ($_POST['email'] ?? ''));
    require_once __DIR__ . '/../app/handlers/signup.php';
    $result = handleSignup();

    if ($result['success']) {
        // Log the user in and send them to the home page (same as google_onboarding.php)
        if (!empty($result['user_id']) && !empty($result['email'])) {
            error_log("DEBUG_LOGIN (auth/signup.php): Signup successful. Calling loginUser for ID " . $result['user_id']);
            loginUser($result['user_id'], $result['email']);
            error_log("DEBUG_LOGIN (auth/signup.php): After loginUser. Session user_id is: " . ($_SESSION['user_id'] ?? 'MISSING'));
        }
        $basePath = getBasePath();
        $next = $_GET['next'] ?? '/';

        error_log("DEBUG_LOGIN (auth/signup.php): Redirecting to: " . $basePath . '/profile');
        session_write_close();
        header('Location: ' . $basePath . '/profile');
        exit;
    } else {
        error_log("DEBUG_LOGIN (auth/signup.php): Signup failed - " . $result['error']);
        $error = $result['error'];
    }
}

// Fetch branches for selection
try {
    require_once __DIR__ . '/../config/db.php';
    global $pdo;
    $stmt = $pdo->query("SELECT id, name FROM branches WHERE is_active = 1 ORDER BY name ASC");
    $activeBranches = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $activeBranches = [];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Sign Up - JustKleek</title>
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/style.css?v=1.0.1">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/assets/css/dv_mobile_nav.css?v=1.0.1">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link href="https://fonts.bunny.net/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .auth-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
        }

        .auth-box {
            background: white;
            border-radius: 14px;
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.1);
            padding: 28px;
            width: 100%;
            max-width: 520px;
        }

        .auth-title {
            font-size: 26px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 6px;
            text-align: center;
        }

        .auth-subtitle {
            color: #4b5563;
            text-align: center;
            margin-bottom: 22px;
            font-size: 15px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            align-items: flex-start;
        }

        .form-group-row>div {
            display: flex;
            flex-direction: column;
        }

        .form-label {
            display: block;
            margin-bottom: 6px;
            color: #1f2937;
            font-weight: 600;
            font-size: 13px;
        }

        .form-input {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            font-size: 15px;
            transition: border-color 0.25s, box-shadow 0.25s;
            box-sizing: border-box;
        }

        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
        }

        .phone-input-wrapper {
            display: flex;
            align-items: center;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: white;
            transition: all 0.2s ease;
        }

        .phone-input-wrapper:focus-within {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
        }

        .phone-country-code {
            padding: 12px 16px;
            background: #f7fafc;
            border-right: 2px solid #e0e0e0;
            color: #1a202c;
            font-weight: 600;
            font-size: 16px;
            user-select: none;
            flex-shrink: 0;
        }

        .phone-input {
            flex: 1;
            border: none;
            padding: 12px 16px;
            font-size: 16px;
            background: transparent;
            color: #1a202c;
            outline: none;
            font-family: inherit;
        }

        .phone-input::placeholder {
            color: #a0aec0;
        }

        .btn-primary {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #667eea 0%, #5568d3 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s, opacity 0.2s;
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.25);
        }

        .btn-secondary {
            width: 100%;
            padding: 13px;
            background: white;
            color: #4b5563;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            box-sizing: border-box;
        }

        .btn-secondary:hover {
            border-color: #d1d5db;
            background-color: #f9fafb;
            color: #4b5563 !important;
            transform: translateY(-1px);
        }

        .alert {
            padding: 12px 14px;
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        .alert-error {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }

        .alert-success {
            background: #dfd;
            color: #3a3;
            border: 1px solid #afa;
        }

        .custom-dropdown {
            position: relative;
            width: 100%;
        }

        .custom-dropdown.active .dropdown-input {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
        }

        .dropdown-input-wrapper {
            position: relative;
            width: 100%;
            display: flex;
            align-items: center;
        }

        .dropdown-input {
            width: 100%;
            padding: 11px 14px;
            padding-right: 40px;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            font-size: 15px;
            cursor: pointer;
            background: white;
            box-sizing: border-box;
            transition: border-color 0.25s, box-shadow 0.25s;
            color: #1f2937;
        }

        .dropdown-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
        }

        .dropdown-input:hover {
            border-color: #d1d5db;
        }

        .dropdown-arrow {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            transition: transform 0.3s;
            z-index: 1;
        }

        .custom-dropdown.active .dropdown-arrow {
            transform: translateY(-50%) rotate(180deg);
        }

        .dropdown-list {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1.5px solid #667eea;
            border-top: none;
            border-radius: 0 0 10px 10px;
            max-height: 400px;
            z-index: 3000;
            display: none;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.14);
            margin-top: -1.5px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .custom-dropdown.active .dropdown-list {
            display: block;
        }

        .dropdown-search {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
            background: white;
            flex-shrink: 0;
        }

        .dropdown-search-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .dropdown-options {
            flex: 1;
            overflow-y: auto;
        }

        .dropdown-option {
            display: block;
            width: 100%;
            padding: 12px 16px;
            text-align: left;
            border: none;
            background: white;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.2s;
            box-sizing: border-box;
        }

        .dropdown-option:hover {
            background: #f5f5f5;
        }

        .dropdown-option.selected {
            background: #667eea;
            color: white;
        }

        .location-actions {
            display: flex;
            gap: 8px;
        }

        .location-btn {
            padding: 10px 16px;
            border: 2px solid #667eea;
            border-radius: 8px;
            background: white;
            color: #667eea;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            width: 100%;
        }

        .location-btn:hover {
            background: #f8f9ff;
        }

        .location-btn.use-current {
            background: linear-gradient(135deg, #667eea 0%, #5568d3 100%);
            color: white;
            border-color: transparent;
        }

        .location-btn.use-current:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
        }

        .location-btn:disabled,
        .location-btn.btn-disabled {
            opacity: 0.6;
            cursor: not-allowed;
            pointer-events: none;
            background: #e0e0e0 !important;
            color: #888 !important;
            box-shadow: none !important;
            transform: none !important;
            border-color: #ddd !important;
        }

        .password-match {
            margin-top: 6px;
            font-size: 12px;
            font-weight: 500;
            display: none;
        }

        .password-match.match {
            color: #38a169;
            display: block;
        }

        .password-match.mismatch {
            color: #e53e3e;
            display: block;
        }

        .form-hint {
            display: block;
            margin-top: 6px;
            color: #718096;
            font-size: 12px;
        }

        .street-address-wrapper {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .street-address-wrapper .form-input {
            flex: 1;
        }

        .locate-btn {
            background: #667eea;
            color: white;
            border: none;
            width: 42px;
            height: 42px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            flex-shrink: 0;
            padding: 0;
            box-shadow: 0 2px 4px rgba(102, 126, 234, 0.2);
        }

        .locate-btn:hover {
            background: #5568d3;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(102, 126, 234, 0.3);
        }

        .locate-btn:active {
            transform: translateY(0);
        }

        .locate-btn:disabled {
            background: #cbd5e0;
            cursor: not-allowed;
            box-shadow: none;
            transform: none;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .locate-btn.loading svg {
            animation: spin-icon 1s linear infinite;
        }

        @keyframes spin-icon {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }

        @media (max-width: 600px) {
            .form-group-row {
                grid-template-columns: 1fr;
            }
        }

        /* Custom Confirmation Modal */
        .custom-modal {
            position: fixed;
            inset: 0;
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .custom-modal.active {
            display: flex;
            opacity: 1;
            visibility: visible;
        }

        .custom-modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
        }

        .custom-modal-content {
            position: relative;
            background: white;
            border-radius: 14px;
            padding: 28px;
            max-width: 400px;
            width: 100%;
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.2);
            transform: scale(0.9) translateY(20px);
            transition: transform 0.3s ease;
        }

        .custom-modal.active .custom-modal-content {
            transform: scale(1) translateY(0);
        }

        .custom-modal-header {
            text-align: center;
            margin-bottom: 20px;
        }

        .custom-modal-icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 16px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .custom-modal-icon.error {
            background: #fee;
        }

        .custom-modal-icon.error svg {
            color: #c33;
        }

        .custom-modal-icon.warning {
            background: #fff5e6;
        }

        .custom-modal-icon.warning svg {
            color: #d97706;
        }

        .custom-modal-icon svg {
            width: 28px;
            height: 28px;
        }

        .custom-modal-title {
            font-size: 20px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
        }

        .custom-modal-message {
            font-size: 15px;
            color: #4b5563;
            line-height: 1.5;
            text-align: center;
            margin-bottom: 24px;
        }

        .custom-modal-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .custom-modal-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            min-width: 100px;
        }

        .custom-modal-btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #5568d3 100%);
            color: white;
        }

        .custom-modal-btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.25);
        }

        @media (max-width: 480px) {
            .custom-modal-content {
                padding: 24px 20px;
            }

            .custom-modal-title {
                font-size: 18px;
            }

            .custom-modal-message {
                font-size: 14px;
            }

            .custom-modal-actions {
                flex-direction: column;
            }

            .custom-modal-btn {
                width: 100%;
            }
        }

        /* OTP Verification Modal */
        .otp-modal {
            position: fixed;
            inset: 0;
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .otp-modal.active {
            display: flex;
            opacity: 1;
            visibility: visible;
        }

        .otp-modal-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(4px);
        }

        .otp-modal-content {
            position: relative;
            background: white;
            border-radius: 20px;
            padding: 32px;
            max-width: 420px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            transform: scale(0.9) translateY(20px);
            transition: transform 0.3s ease;
        }

        .otp-modal.active .otp-modal-content {
            transform: scale(1) translateY(0);
        }

        .otp-modal-header {
            text-align: center;
            margin-bottom: 24px;
        }

        .otp-modal-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 16px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .otp-modal-icon svg {
            width: 32px;
            height: 32px;
            color: white;
        }

        .otp-modal-title {
            font-size: 24px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
        }

        .otp-modal-subtitle {
            font-size: 14px;
            color: #64748b;
            line-height: 1.5;
        }

        .otp-modal-email {
            font-weight: 600;
            color: #667eea;
        }

        .otp-input-container {
            margin: 24px 0;
        }

        .otp-input-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 12px;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .otp-input-wrapper {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin-bottom: 8px;
        }

        .otp-input-box {
            width: 50px;
            height: 60px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 28px;
            font-weight: 700;
            text-align: center;
            color: #667eea;
            font-family: 'Courier New', monospace;
            transition: all 0.2s ease;
            background: #f8f9fa;
            outline: none;
        }

        .otp-input-box:focus {
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        .otp-input-box.filled {
            background: white;
            border-color: #667eea;
        }

        .otp-hint {
            text-align: center;
            font-size: 13px;
            color: #64748b;
            margin-top: 8px;
        }

        .otp-error,
        .otp-success {
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 14px;
            margin-bottom: 16px;
            text-align: center;
            display: none;
        }

        .otp-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .otp-error.show {
            display: block;
            animation: shake 0.4s ease;
        }

        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            25% {
                transform: translateX(-10px);
            }

            75% {
                transform: translateX(10px);
            }
        }

        .otp-success {
            background: #d1fae5;
            color: #065f46;
        }

        .otp-success.show {
            display: block;
        }

        .otp-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }

        .otp-btn {
            flex: 1;
            padding: 14px 24px;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .otp-btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .otp-btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(102, 126, 234, 0.5);
        }

        .otp-btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .otp-btn-secondary {
            background: #f8fafc;
            color: #334155;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.05);
        }

        .otp-btn-secondary:hover:not(:disabled) {
            background: #eef2f7;
            transform: translateY(-1px);
        }

        .otp-resend {
            text-align: center;
            margin-top: 20px;
            font-size: 13px;
            color: #64748b;
        }

        .otp-resend-link {
            color: #667eea;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }

        .otp-resend-link:hover {
            text-decoration: underline;
        }

        .otp-loading {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        @media (max-width: 600px) {
            .auth-box {
                padding: 24px 20px;
            }

            .auth-title {
                font-size: 24px;
            }

            .form-input {
                padding: 10px 12px;
                font-size: 14px;
            }

            .phone-country-code {
                padding: 10px 12px;
                font-size: 14px;
            }

            .phone-input {
                padding: 10px 12px;
                font-size: 14px;
            }

            .btn-primary,
            .btn-secondary {
                padding: 11px;
                font-size: 14px;
            }
        }

        @media (max-width: 480px) {
            .otp-modal-content {
                padding: 24px 20px;
                border-radius: 16px;
            }

            .otp-modal-title {
                font-size: 20px;
            }

            .otp-input-box {
                width: 45px;
                height: 56px;
                font-size: 24px;
            }

            .otp-actions {
                flex-direction: column;
            }

            .otp-btn {
                width: 100%;
            }
        }
    </style>
    <script src="<?php echo $basePath; ?>/js/prevent-zoom.js"></script>
    <?php require_once __DIR__ . '/../includes/meta_pixel.php'; ?>
</head>

<body>
    <div class="auth-container">
        <div class="auth-box">
            <h1 class="auth-title">Create Account</h1>
            <p class="auth-subtitle">Fill in your details to get started</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo e($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo e($success); ?></div>
            <?php endif; ?>

            <form method="POST" action="" id="signupForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                <div class="form-group form-group-row">
                    <div>
                        <label class="form-label" for="first_name">First Name *</label>
                        <input type="text" id="first_name" name="first_name" class="form-input" placeholder="First name"
                            required autofocus>
                    </div>
                    <div>
                        <label class="form-label" for="last_name">Last Name *</label>
                        <input type="text" id="last_name" name="last_name" class="form-input" placeholder="Last name"
                            required>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="email">Email Address *</label>
                    <input type="email" id="email" name="email" class="form-input" placeholder="Enter your email"
                        required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="phone">Phone Number *</label>
                    <div class="phone-input-wrapper"
                        style="display: flex; align-items: center; border: 1.5px solid #e5e7eb; border-radius: 10px; background: white; transition: all 0.2s ease;">
                        <span class="phone-country-code"
                            style="padding: 11px 16px; background: #f7fafc; border-right: 1.5px solid #e5e7eb; color: #1a202c; font-weight: 600; font-size: 16px; user-select: none; flex-shrink: 0; border-radius: 10px 0 0 10px;">+977</span>
                        <input type="tel" id="phone" name="phone" class="phone-input"
                            placeholder="Enter your phone number" pattern="[0-9]{10}" maxlength="10" required
                            style="flex: 1; border: none; padding: 11px 16px; font-size: 15px; background: transparent; color: #1a202c; outline: none; font-family: inherit;">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="delivery_location">Delivery Location *</label>
                    <select id="delivery_location" name="delivery_location_select" class="form-input" required
                        onchange="toggleManualLocation('delivery_location', 'manualLocationContainer')">
                        <option value="" disabled selected>Select Area</option>
                        <option value="Bharatpur-11">Bharatpur-11</option>
                        <option value="Bharatpur-10">Bharatpur-10</option>
                        <option value="Bharatpur-12">Bharatpur-12</option>
                        <option value="Bharatpur-9">Bharatpur-9</option>
                        <option value="Rampur">Rampur</option>
                        <option value="Sauraha">Sauraha</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-group" id="manualLocationContainer" style="display: none; margin-top: 10px;">
                    <label class="form-label" for="manual_delivery_location">Manually Enter Location *</label>
                    <input type="text" id="manual_delivery_location" name="manual_delivery_location" class="form-input"
                        placeholder="Type your area name">
                </div>

                <div class="form-group">
                    <label class="form-label" for="street_location">Street Location *</label>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <input type="text" id="street_location" name="street_location" class="form-input"
                            placeholder="Please click 'Use Current Location'" readonly required>
                        <button type="button" class="btn-secondary use-current-location-btn"
                            style="flex-shrink: 0; padding: 11px 14px; width: auto; font-size: 13px;"
                            onmouseover="this.style.color='#4b5563';" onmouseout="this.style.color='';">Use Current
                            Location</button>
                        <input type="hidden" id="location_lat" name="location_lat">
                        <input type="hidden" id="location_lng" name="location_lng">
                    </div>
                </div>


                <div class="form-group">
                    <label class="form-label" for="branch_id">Select Branch *</label>
                    <select id="branch_id" name="branch_id" class="form-input" required>
                        <option value="" disabled selected>Select your branch</option>
                        <?php foreach ($activeBranches ?? [] as $branch): ?>
                            <option value="<?php echo (int)$branch['id']; ?>"><?php echo htmlspecialchars((string)$branch['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password *</label>
                    <input type="password" id="password" name="password" class="form-input"
                        placeholder="Create a password" required minlength="8">
                    <span class="form-hint">Must be at least 8 characters long</span>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password_confirm">Confirm Password *</label>
                    <input type="password" id="password_confirm" name="password_confirm" class="form-input"
                        placeholder="Confirm your password" required>
                    <span class="password-match" id="passwordMatch"></span>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 24px;">
                    <button type="submit" name="signup" class="btn-primary"
                        style="display: flex; align-items: center; justify-content: center;">Sign Up</button>
                    <a href="<?php echo $basePath; ?>/auth/login.php?next=<?php echo urlencode($_GET['next'] ?? '/'); ?>"
                        class="btn-secondary">Back</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Custom Alert Modal -->
    <div class="custom-modal" id="customAlertModal">
        <div class="custom-modal-backdrop" onclick="closeCustomAlert()"></div>
        <div class="custom-modal-content">
            <div class="custom-modal-header">
                <div class="custom-modal-icon warning" id="customAlertIcon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <h2 class="custom-modal-title" id="customAlertTitle">Action Required</h2>
            </div>
            <div class="custom-modal-message" id="customAlertMessage">Please fill in all required fields.</div>
            <div class="custom-modal-actions">
                <button type="button" class="custom-modal-btn custom-modal-btn-primary" onclick="closeCustomAlert()">OK</button>
            </div>
        </div>
    </div>

    <script>
        function showCustomAlert(title, message, type = 'warning') {
            const modal = document.getElementById('customAlertModal');
            const titleEl = document.getElementById('customAlertTitle');
            const messageEl = document.getElementById('customAlertMessage');
            const iconEl = document.getElementById('customAlertIcon');

            if (!modal || !titleEl || !messageEl || !iconEl) return;

            titleEl.textContent = title;
            messageEl.textContent = message;
            
            // Set icon type
            iconEl.className = 'custom-modal-icon ' + type;
            
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeCustomAlert() {
            const modal = document.getElementById('customAlertModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }
    </script>
    <script>
        const basePath = '<?php echo $basePath; ?>';

        // Toggle manual location input
        function toggleManualLocation(selectId, containerId) {
            const select = document.getElementById(selectId);
            const container = document.getElementById(containerId);
            if (select && container) {
                const manualInput = container.querySelector('input');
                if (select.value === 'Other') {
                    container.style.display = 'block';
                    if (manualInput) {
                        manualInput.required = true;
                        manualInput.focus();
                    }
                } else {
                    container.style.display = 'none';
                    if (manualInput) {
                        manualInput.required = false;
                        manualInput.value = ''; // Clear if hidden
                    }
                }
            }
        }

        // Initialize on load
        document.addEventListener('DOMContentLoaded', function () {
            toggleManualLocation('delivery_location', 'manualLocationContainer');
        });


        // Phone number validation - only allow digits
        const phoneInput = document.getElementById('phone');
        if (phoneInput) {
            phoneInput.addEventListener('input', function (e) {
                // Remove any non-digit characters
                this.value = this.value.replace(/[^0-9]/g, '');
            });

            phoneInput.addEventListener('paste', function (e) {
                e.preventDefault();
                const paste = (e.clipboardData || window.clipboardData).getData('text');
                const digitsOnly = paste.replace(/[^0-9]/g, '');
                this.value = digitsOnly;
            });
        }

        // Password match validation
        const passwordInput = document.getElementById('password');
        const passwordConfirmInput = document.getElementById('password_confirm');
        const passwordMatch = document.getElementById('passwordMatch');
        const form = document.getElementById('signupForm');
        const inputs = form.querySelectorAll('input[required]');

        function checkPasswordMatch() {
            const password = passwordInput.value;
            const confirm = passwordConfirmInput.value;

            if (confirm.length === 0) {
                passwordMatch.className = 'password-match';
                return true;
            }

            if (password === confirm && password.length >= 8) {
                passwordMatch.textContent = '✓ Passwords match';
                passwordMatch.className = 'password-match match';
                return true;
            } else {
                passwordMatch.textContent = '✗ Passwords do not match';
                passwordMatch.className = 'password-match mismatch';
                return false;
            }
        }

        passwordInput.addEventListener('input', checkPasswordMatch);
        passwordConfirmInput.addEventListener('input', checkPasswordMatch);

        // Handle phone and delivery location before submission
        form.addEventListener('submit', function (e) {
            const phoneValue = phoneInput.value.replace(/[^0-9]/g, '');
            if (phoneValue.length !== 10) {
                e.preventDefault();
                showCustomAlert('Invalid Phone', 'Please enter a valid phone number (10 digits)');
                phoneInput.focus();
                return;
            }

            // Remove the original phone input name to prevent double submission
            phoneInput.removeAttribute('name');

            // Add hidden phone field with +977 prefix
            const phoneField = document.createElement('input');
            phoneField.type = 'hidden';
            phoneField.name = 'phone';
            phoneField.value = '+977' + phoneValue;
            form.appendChild(phoneField);

            // Handle delivery location
            const deliverySelect = document.getElementById('delivery_location');
            const manualInput = document.getElementById('manual_delivery_location');
            const finalValue = (deliverySelect.value === 'Other') ? manualInput.value.trim() : deliverySelect.value;

            if (!finalValue) {
                e.preventDefault();
                showCustomAlert('Location Missing', 'Please select or enter your delivery location');
                deliverySelect.focus();
                return;
            }

            // Verify street location
            const streetInput = document.getElementById('street_location');
            if (!streetInput.value.trim()) {
                e.preventDefault();
                showCustomAlert('Street Location Required', 'Please provide your street location (click "Use Current Location")');
                return;
            }

            // Add hidden field for delivery_location
            const deliveryField = document.createElement('input');
            deliveryField.type = 'hidden';
            deliveryField.name = 'delivery_location';
            deliveryField.value = finalValue;
            form.appendChild(deliveryField);
        });
    </script>

    <!-- OTP Verification Modal -->
    <div class="otp-modal <?php echo $showOtpModal ? 'active' : ''; ?>" id="otpModal">
        <div class="otp-modal-backdrop"></div>
        <div class="otp-modal-content">
            <div class="otp-modal-header">
                <div class="otp-modal-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"
                            stroke-linecap="round" stroke-linejoin="round" />
                        <polyline points="22,6 12,13 2,6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
                <h2 class="otp-modal-title">Verify Your Email</h2>
                <p class="otp-modal-subtitle">
                    We've sent a 6-digit code to<br>
                    <span class="otp-modal-email"
                        id="otpEmailDisplay"><?php echo htmlspecialchars($signupEmail); ?></span>
                </p>
            </div>

            <div class="otp-error" id="otpError"></div>
            <div class="otp-success" id="otpSuccess">✓ Email verified successfully! Redirecting...</div>

            <div class="otp-input-container">
                <label class="otp-input-label">Enter Verification Code</label>
                <div class="otp-input-wrapper">
                    <input type="text" class="otp-input-box" id="otp1" maxlength="1" pattern="[0-9]" inputmode="numeric"
                        autocomplete="off">
                    <input type="text" class="otp-input-box" id="otp2" maxlength="1" pattern="[0-9]" inputmode="numeric"
                        autocomplete="off">
                    <input type="text" class="otp-input-box" id="otp3" maxlength="1" pattern="[0-9]" inputmode="numeric"
                        autocomplete="off">
                    <input type="text" class="otp-input-box" id="otp4" maxlength="1" pattern="[0-9]" inputmode="numeric"
                        autocomplete="off">
                    <input type="text" class="otp-input-box" id="otp5" maxlength="1" pattern="[0-9]" inputmode="numeric"
                        autocomplete="off">
                    <input type="text" class="otp-input-box" id="otp6" maxlength="1" pattern="[0-9]" inputmode="numeric"
                        autocomplete="off">
                </div>
                <p class="otp-hint">Enter the 6-digit code from your email</p>
            </div>

            <div class="otp-actions">
                <button type="button" class="otp-btn otp-btn-primary" id="verifyOtpBtn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke-linecap="round" stroke-linejoin="round" />
                        <polyline points="22 4 12 14.01 9 11.01" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Verify
                </button>
                <button type="button" class="otp-btn otp-btn-secondary" id="newSignupBtn">
                    Sign up another account
                </button>
            </div>

            <div class="otp-resend">
                Didn't receive the code?
                <a href="#" class="otp-resend-link" id="resendOtpLink">Resend</a>
            </div>
        </div>
    </div>

    <script>
        // OTP Modal Functionality
        (function () {
            const otpModal = document.getElementById('otpModal');
            const otpInputs = [
                document.getElementById('otp1'),
                document.getElementById('otp2'),
                document.getElementById('otp3'),
                document.getElementById('otp4'),
                document.getElementById('otp5'),
                document.getElementById('otp6')
            ];
            const verifyBtn = document.getElementById('verifyOtpBtn');
            const resendLink = document.getElementById('resendOtpLink');
            const newSignupBtn = document.getElementById('newSignupBtn');
            const otpError = document.getElementById('otpError');
            const otpSuccess = document.getElementById('otpSuccess');

            const signupEmail = '<?php echo htmlspecialchars($signupEmail); ?>';
            const basePath = '<?php echo $basePath; ?>';

            // Show modal if signup was successful
            <?php if ($showOtpModal): ?>             if (otpModal) { otpModal.classList.add('active'); document.body.style.overflow = 'hidden'; setTimeout(() => { if (otpInputs[0]) otpInputs[0].focus(); }, 300); }
            <?php endif; ?>

            // Handle OTP input boxes
            otpInputs.forEach((input, index) => {
                if (!input) return;

                // Only allow numbers
                input.addEventListener('input', function (e) {
                    this.value = this.value.replace(/[^0-9]/g, '');

                    if (this.value) {
                        this.classList.add('filled');
                        // Move to next input
                        if (index < otpInputs.length - 1 && otpInputs[index + 1]) {
                            otpInputs[index + 1].focus();
                        } else {
                            // All filled, auto-verify
                            verifyOtp();
                        }
                    } else {
                        this.classList.remove('filled');
                    }
                });

                // Handle backspace
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Backspace' && !this.value && index > 0) {
                        otpInputs[index - 1].focus();
                        otpInputs[index - 1].value = '';
                        otpInputs[index - 1].classList.remove('filled');
                    }
                });

                // Handle paste
                input.addEventListener('paste', function (e) {
                    e.preventDefault();
                    const pasted = (e.clipboardData || window.clipboardData).getData('text');
                    const numbers = pasted.replace(/[^0-9]/g, '').substring(0, 6);

                    numbers.split('').forEach((num, i) => {
                        if (otpInputs[i]) {
                            otpInputs[i].value = num;
                            otpInputs[i].classList.add('filled');
                        }
                    });

                    // Focus last filled input
                    const lastFilled = Math.min(numbers.length - 1, otpInputs.length - 1);
                    if (otpInputs[lastFilled]) {
                        otpInputs[lastFilled].focus();
                    }

                    // Auto-verify if all 6 digits pasted
                    if (numbers.length === 6) {
                        setTimeout(() => verifyOtp(), 100);
                    }
                });

                // Handle arrow keys
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'ArrowLeft' && index > 0) {
                        otpInputs[index - 1].focus();
                    } else if (e.key === 'ArrowRight' && index < otpInputs.length - 1) {
                        otpInputs[index + 1].focus();
                    }
                });
            });

            // Get OTP value from all inputs
            function getOtpValue() {
                return otpInputs.map(input => input ? input.value : '').join('');
            }

            // Clear all OTP inputs
            function clearOtpInputs() {
                otpInputs.forEach(input => {
                    if (input) {
                        input.value = '';
                        input.classList.remove('filled');
                        input.disabled = false;
                    }
                });
            }

            // Verify OTP function
            function verifyOtp() {
                const otp = getOtpValue();

                if (otp.length !== 6) {
                    showError('Please enter a 6-digit code');
                    return;
                }

                // Disable all inputs and button
                otpInputs.forEach(input => {
                    if (input) input.disabled = true;
                });
                verifyBtn.disabled = true;
                verifyBtn.innerHTML = '<span class="otp-loading"></span> Verifying...';
                hideError();
                hideSuccess();

                // Send AJAX request
                const formData = new FormData();
                formData.append('otp', otp);
                formData.append('email', signupEmail);
                formData.append('csrf_token', '<?php echo generateCSRFToken(); ?>');

                fetch(basePath + '/auth/verify_otp.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showSuccess();
                            // Clear session
                            fetch(basePath + '/auth/clear_signup_session.php', { method: 'POST' });
                            // Redirect to home page after 1 second (user is already logged in)
                            setTimeout(() => {
                                window.location.href = basePath + '/';
                            }, 1000);
                        } else {
                            showError(data.error || 'Invalid verification code. Please try again.');
                            clearOtpInputs();
                            verifyBtn.disabled = false;
                            verifyBtn.innerHTML = `
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke-linecap="round" stroke-linejoin="round"/>
                                <polyline points="22 4 12 14.01 9 11.01" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            Verify
                        `;
                            if (otpInputs[0]) otpInputs[0].focus();
                        }
                    })
                    .catch(error => {
                        showError('Network error. Please check your connection and try again.');
                        clearOtpInputs();
                        verifyBtn.disabled = false;
                        verifyBtn.innerHTML = `
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke-linecap="round" stroke-linejoin="round"/>
                            <polyline points="22 4 12 14.01 9 11.01" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        Verify
                    `;
                        if (otpInputs[0]) otpInputs[0].focus();
                    });
            }

            // Resend OTP
            if (resendLink) {
                resendLink.addEventListener('click', function (e) {
                    e.preventDefault();

                    resendLink.style.opacity = '0.6';
                    resendLink.textContent = 'Sending...';

                    const formData = new FormData();
                    formData.append('email', signupEmail);
                    formData.append('csrf_token', '<?php echo generateCSRFToken(); ?>');

                    fetch(basePath + '/auth/resend_otp.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                resendLink.textContent = 'Code sent!';
                                setTimeout(() => {
                                    resendLink.textContent = 'Resend';
                                    resendLink.style.opacity = '1';
                                }, 2000);
                            } else {
                                showError(data.error || 'Failed to resend code. Please try again.');
                                resendLink.textContent = 'Resend';
                                resendLink.style.opacity = '1';
                            }
                        })
                        .catch(error => {
                            showError('Network error. Please try again.');
                            resendLink.textContent = 'Resend';
                            resendLink.style.opacity = '1';
                        });
                });
            }

            // Start a fresh signup (clears current OTP session and reloads form)
            if (newSignupBtn) {
                newSignupBtn.addEventListener('click', function () {
                    newSignupBtn.disabled = true;
                    newSignupBtn.textContent = 'Opening form...';
                    fetch(basePath + '/auth/clear_signup_session.php', { method: 'POST' })
                        .finally(() => {
                            window.location.href = basePath + '/auth/signup.php';
                        });
                });
            }

            // Verify button click
            if (verifyBtn) {
                verifyBtn.addEventListener('click', verifyOtp);
            }

            // Helper functions
            function showError(message) {
                if (otpError) {
                    otpError.textContent = message;
                    otpError.classList.add('show');
                }
            }

            function hideError() {
                if (otpError) {
                    otpError.classList.remove('show');
                }
            }

            function showSuccess() {
                if (otpSuccess) {
                    otpSuccess.classList.add('show');
                }
            }

            function hideSuccess() {
                if (otpSuccess) {
                    otpSuccess.classList.remove('show');
                }
            }
        })();
    </script>





    <script>
        // Geolocation Script
        document.addEventListener('DOMContentLoaded', function () {
            const locateBtns = document.querySelectorAll('.use-current-location-btn');
            locateBtns.forEach(btn => {
                btn.addEventListener('click', function () {
                    const container = this.closest('.form-group') || this.parentElement;

                    if (!navigator.geolocation) {
                        console.warn("Geolocation is not supported on this device.");
                        return;
                    }

                    const originalText = this.textContent;
                    this.textContent = "Getting...";
                    this.disabled = true;

                    navigator.geolocation.getCurrentPosition(
                        function (position) {
                            btn.textContent = originalText;
                            btn.disabled = false;

                            const lat = position.coords.latitude;
                            const lng = position.coords.longitude;

                            // Field mapping
                            const latInput = document.querySelector('#location_lat, #editLocationLat, #delivery_lat');
                            const lngInput = document.querySelector('#location_lng, #editLocationLng, #delivery_lng');
                            const streetInput = document.querySelector('#street_location, #editStreetLocation');

                            if (latInput) latInput.value = lat;
                            if (lngInput) lngInput.value = lng;

                            // Show coordinates in Street Location as requested
                            if (streetInput) {
                                streetInput.value = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
                                streetInput.dispatchEvent(new Event('input'));
                            }
                        },
                        function (error) {
                            btn.textContent = originalText;
                            btn.disabled = false;
                            console.warn("Could not get location:", error);
                        },
                        {
                            enableHighAccuracy: true,
                            timeout: 10000,
                            maximumAge: 0
                        }
                    );
                });
            });
        });
    </script>
</body>

</html>