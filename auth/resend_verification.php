<?php
/**
 * Resend Verification Code (OTP)
 */

require_once __DIR__ . '/../app/functions/security.php';
require_once __DIR__ . '/../app/functions/auth.php';
require_once __DIR__ . '/../app/functions/email.php';

initSecureSession();

$basePath = getBasePath();

$error = '';
$success = '';

// Handle resend request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend'])) {
    require_once __DIR__ . '/../app/handlers/user_resend_verification.php';
    $result = handleResendVerification();

    if ($result['success']) {
        $success = 'Verification code has been sent. Please check your inbox.';
    } else {
        $error = $result['error'];
    }
}

// If user is logged in, show form
if (isUserLoggedIn()) {
    $user = getCurrentUser();
} else {
    // Allow email input for non-logged-in users
    $user = null;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Resend Verification - JustKleek</title>
    <link rel="stylesheet" href="../css/style.css">
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
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            padding: 40px;
            width: 100%;
            max-width: 420px;
        }

        .auth-title {
            font-size: 28px;
            font-weight: 700;
            color: #333;
            margin-bottom: 8px;
            text-align: center;
        }

        .auth-subtitle {
            color: #666;
            text-align: center;
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }

        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
            box-sizing: border-box;
        }

        .form-input:focus {
            outline: none;
            border-color: #667eea;
        }

        .form-input:read-only {
            background: #f5f5f5;
            cursor: not-allowed;
        }

        .btn-primary {
            width: 100%;
            padding: 14px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }

        .btn-primary:hover {
            background: #5568d3;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-error {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }

        .alert-success {
            background: #efe;
            color: #3c3;
            border: 1px solid #cfc;
        }

        .auth-link {
            text-align: center;
            margin-top: 20px;
            color: #666;
        }

        .auth-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }

        .auth-link a:hover {
            text-decoration: underline;
        }
    </style>
    <script src="<?php echo getBasePath(); ?>/js/prevent-zoom.js"></script>
    <?php require_once __DIR__ . '/../includes/meta_pixel.php'; ?>
</head>

<body>
    <div class="auth-container">
        <div class="auth-box">
            <h1 class="auth-title">Resend Verification Code</h1>
            <p class="auth-subtitle">We'll send you a new 6-digit verification code</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo e($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo e($success); ?></div>
                <p style="text-align: center; margin-top: 15px; color: #666; font-size: 14px;">
                    Check your email for the 6-digit OTP code. Enter it in the verification popup when you log in or sign
                    up.
                </p>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                <div class="form-group">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-input"
                        value="<?php echo e($user['email'] ?? ''); ?>" <?php echo $user ? 'readonly' : 'required'; ?>>
                </div>

                <button type="submit" name="resend" class="btn-primary">Resend Verification Code</button>
            </form>

            <div class="auth-link">
                <a href="/auth/login.php">Back to Login</a>
            </div>
        </div>
    </div>
</body>

</html>
