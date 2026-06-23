<?php
/**
 * Email Verification Page
 */

require_once __DIR__ . '/../app/functions/security.php';
require_once __DIR__ . '/../app/functions/auth.php';
require_once __DIR__ . '/../app/functions/email.php';
require_once __DIR__ . '/../config/db.php';

initSecureSession();

// Get base path for URLs
$basePath = getBasePath();

$error = '';
$success = '';
$required = isset($_GET['required']);
$email = isset($_GET['email']) ? trim($_GET['email']) : '';
$userId = null;

// Get user ID if email is provided
if (!empty($email)) {
    $user = findUserByEmail($email);
    if ($user) {
        $userId = $user['id'];
    }
}

// Handle OTP verification via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp'])) {
    $otp = trim($_POST['otp'] ?? '');
    $postEmail = trim($_POST['email'] ?? '');

    // Get user ID from email if provided
    $postUserId = null;
    if (!empty($postEmail)) {
        $user = findUserByEmail($postEmail);
        if ($user) {
            $postUserId = $user['id'];
        }
    }

    if (empty($otp)) {
        $error = 'Please enter the OTP code';
    } else {
        $result = verifyEmailOTP($otp, $postUserId);

        if ($result['success']) {
            // Log the user in if not already logged in
            if (!isUserLoggedIn() && $user) {
                loginUser($user['id'], $user['email']);
            }

            $success = 'Your email has been verified successfully!';

            // Redirect based on login status
            if (isUserLoggedIn()) {
                // Redirect to index page if already logged in
                header('refresh:2;url=' . $basePath . '/');
            } else {
                // Redirect to login page if not logged in
                header('refresh:2;url=' . $basePath . '/auth/login.php?verified=1');
            }
            exit;
        } else {
            $error = $result['error'];
            $email = $postEmail; // Keep email in form
        }
    }
}

// Backward compatibility: Handle old token-based verification (if someone has old link)
if (isset($_GET['token'])) {
    $token = $_GET['token'];
    $result = verifyEmailOTP($token);

    if ($result['success']) {
        $success = 'Your email has been verified successfully! Your account has been created.';
        header('refresh:3;url=' . $basePath . '/auth/login.php?verified=1');
    } else {
        $error = 'This verification link is no longer valid. Please use the OTP code sent to your email.';
    }
}

// Helper function to get user by ID
function findUserById($userId)
{
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetch() ?: [];
    } catch (PDOException $e) {
        return [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Verify Email - JustKleek</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link href="https://fonts.bunny.net/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }

        .auth-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        .auth-container::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: pulse 4s ease-in-out infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                transform: scale(1) rotate(0deg);
                opacity: 0.3;
            }

            50% {
                transform: scale(1.1) rotate(180deg);
                opacity: 0.5;
            }
        }

        .auth-box {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 50px 40px;
            width: 100%;
            max-width: 500px;
            text-align: center;
            position: relative;
            z-index: 1;
            animation: slideUp 0.6s ease-out;
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

        .auth-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 25px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: bounce 2s ease-in-out infinite;
        }

        @keyframes bounce {

            0%,
            100% {
                transform: translateY(0) scale(1);
            }

            50% {
                transform: translateY(-10px) scale(1.05);
            }
        }

        .auth-icon svg {
            width: 45px;
            height: 45px;
            color: white;
        }

        .auth-title {
            font-size: 32px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }

        .auth-subtitle {
            color: #64748b;
            margin-bottom: 30px;
            font-size: 16px;
            line-height: 1.6;
        }

        .alert {
            padding: 18px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            text-align: left;
            animation: fadeIn 0.5s ease-out;
            border-left: 5px solid;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateX(-10px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .alert-error {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
            border-left-color: #dc2626;
        }

        .alert-success {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
            border-left-color: #10b981;
        }

        .alert-icon {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            font-size: 15px;
        }

        .alert-icon svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 32px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            margin-top: 20px;
            box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(102, 126, 234, 0.5);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-primary svg {
            width: 18px;
            height: 18px;
        }

        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 14px 32px;
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            margin-top: 10px;
        }

        .btn-secondary:hover {
            background: #f8f9fa;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.2);
        }

        .btn-secondary svg {
            width: 18px;
            height: 18px;
        }

        .countdown {
            margin-top: 15px;
            font-size: 14px;
            color: #64748b;
            font-weight: 500;
        }

        @media (max-width: 600px) {
            .auth-box {
                padding: 40px 25px;
                border-radius: 16px;
            }

            .auth-title {
                font-size: 26px;
            }

            .auth-icon {
                width: 70px;
                height: 70px;
            }

            .auth-icon svg {
                width: 40px;
                height: 40px;
            }

            .btn-primary,
            .btn-secondary {
                width: 100%;
                padding: 14px 24px;
            }
        }
    </style>
    <script src="<?php echo $basePath; ?>/js/prevent-zoom.js"></script>
    <?php require_once __DIR__ . '/../includes/meta_pixel.php'; ?>
</head>

<body>
    <div class="auth-container">
        <div class="auth-box">
            <?php if ($error): ?>
                <div class="auth-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10" stroke-linecap="round" stroke-linejoin="round" />
                        <line x1="12" y1="8" x2="12" y2="12" stroke-linecap="round" stroke-linejoin="round" />
                        <line x1="12" y1="16" x2="12.01" y2="16" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
                <h1 class="auth-title">Verification Failed</h1>
                <div class="alert alert-error">
                    <div class="alert-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10" stroke-linecap="round" stroke-linejoin="round" />
                            <line x1="12" y1="8" x2="12" y2="12" stroke-linecap="round" stroke-linejoin="round" />
                            <line x1="12" y1="16" x2="12.01" y2="16" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span><?php echo e($error); ?></span>
                    </div>
                </div>
                <p class="auth-subtitle">The verification code may have expired or is invalid. Please request a new
                    verification code.</p>
                <?php if (isUserLoggedIn()): ?>
                    <a href="<?php echo $basePath; ?>/auth/resend_verification.php" class="btn-secondary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M1 4v6h6M23 20v-6h-6" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15" stroke-linecap="round"
                                stroke-linejoin="round" />
                        </svg>
                        Resend Verification Code
                    </a>
                <?php endif; ?>
            <?php elseif ($success): ?>
                <div class="auth-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke-linecap="round" stroke-linejoin="round" />
                        <polyline points="22 4 12 14.01 9 11.01" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
                <h1 class="auth-title">Email Verified!</h1>
                <div class="alert alert-success">
                    <div class="alert-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke-linecap="round" stroke-linejoin="round" />
                            <polyline points="22 4 12 14.01 9 11.01" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span><?php echo e($success); ?></span>
                    </div>
                </div>
                <p class="auth-subtitle">Your email has been successfully verified. You can now log in to your account.</p>
                <div class="countdown" id="countdown">Redirecting to login page in <span id="timer">3</span> seconds...
                </div>
                <a href="<?php echo $basePath; ?>/auth/login.php?verified=1" class="btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M13.8 12H3" stroke-linecap="round"
                            stroke-linejoin="round" />
                    </svg>
                    Go to Login Now
                </a>
                <script>
                    let timeLeft = 3;
                    const timerEl = document.getElementById('timer');
                    const countdownEl = document.getElementById('countdown');
                    const interval = setInterval(() => {
                        timeLeft--;
                        if (timerEl) timerEl.textContent = timeLeft;
                        if (timeLeft <= 0) {
                            clearInterval(interval);
                            if (countdownEl) countdownEl.textContent = 'Redirecting now...';
                        }
                    }, 1000);
                </script>
            <?php elseif ($required): ?>
                <div class="auth-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"
                            stroke-linecap="round" stroke-linejoin="round" />
                        <polyline points="22,6 12,13 2,6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
                <h1 class="auth-title">Email Verification Required</h1>
                <p class="auth-subtitle">
                    <?php echo e($_SESSION['verification_required_message'] ?? 'Please verify your email address to continue accessing your account.'); ?>
                </p>
                <?php if (isUserLoggedIn()): ?>
                    <a href="<?php echo $basePath; ?>/auth/resend_verification.php" class="btn-primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M1 4v6h6M23 20v-6h-6" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15" stroke-linecap="round"
                                stroke-linejoin="round" />
                        </svg>
                        Resend Verification OTP
                    </a>
                <?php else: ?>
                    <a href="<?php echo $basePath; ?>/auth/login.php" class="btn-primary">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M13.8 12H3" stroke-linecap="round"
                                stroke-linejoin="round" />
                        </svg>
                        Go to Login
                    </a>
                <?php endif; ?>
            <?php else: ?>
                <div class="auth-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"
                            stroke-linecap="round" stroke-linejoin="round" />
                        <polyline points="22,6 12,13 2,6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
                <h1 class="auth-title">Enter Verification Code</h1>
                <p class="auth-subtitle">
                    <?php if (!empty($email)): ?>
                        We've sent a 6-digit verification code to<br>
                        <strong style="color: #667eea;"><?php echo htmlspecialchars($email); ?></strong><br>
                        Please enter it below to verify your account.
                    <?php else: ?>
                        We've sent a 6-digit verification code to your email address. Please enter it below to verify your
                        account.
                    <?php endif; ?>
                </p>

                <form method="POST" action="" style="margin-top: 30px;">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-error" style="margin-bottom: 20px;">
                            <div class="alert-icon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10" stroke-linecap="round" stroke-linejoin="round" />
                                    <line x1="12" y1="8" x2="12" y2="12" stroke-linecap="round" stroke-linejoin="round" />
                                    <line x1="12" y1="16" x2="12.01" y2="16" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <span><?php echo e($error); ?></span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($email)): ?>
                        <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                    <?php endif; ?>

                    <div style="margin-bottom: 20px;">
                        <label for="otp"
                            style="display: block; margin-bottom: 8px; font-weight: 600; color: #1a202c; font-size: 14px;">Verification
                            Code</label>
                        <input type="text" id="otp" name="otp" maxlength="6" pattern="[0-9]{6}" required
                            autocomplete="one-time-code" style="
                                width: 100%;
                                padding: 16px;
                                font-size: 32px;
                                font-weight: 700;
                                text-align: center;
                                letter-spacing: 8px;
                                border: 2px solid #e2e8f0;
                                border-radius: 12px;
                                font-family: 'Courier New', monospace;
                                color: #667eea;
                                transition: all 0.3s ease;
                            "
                            oninput="this.value = this.value.replace(/[^0-9]/g, ''); if(this.value.length === 6) { this.form.submit(); }"
                            placeholder="000000">
                        <p style="margin-top: 8px; font-size: 13px; color: #64748b; text-align: center;">Enter the 6-digit
                            code from your email</p>
                    </div>

                    <button type="submit" class="btn-primary" style="width: 100%; margin-top: 10px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke-linecap="round" stroke-linejoin="round" />
                            <polyline points="22 4 12 14.01 9 11.01" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        Verify Email
                    </button>
                </form>

                <?php if (isUserLoggedIn() || !empty($email)): ?>
                    <a href="<?php echo $basePath; ?>/auth/resend_verification.php<?php echo !empty($email) ? '?email=' . urlencode($email) : ''; ?>"
                        class="btn-secondary" style="width: 100%; margin-top: 15px; text-decoration: none;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M1 4v6h6M23 20v-6h-6" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15" stroke-linecap="round"
                                stroke-linejoin="round" />
                        </svg>
                        Resend Verification Code
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>
