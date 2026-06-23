<?php
/**
 * Complete Profile Page for New Google Users
 */

require_once __DIR__ . '/../app/functions/security.php';
require_once __DIR__ . '/../app/functions/auth.php';

initSecureSession();

// Get base path for URLs
$basePath = getBasePath();

// Debug logging
error_log("Google Complete Profile - Session data: " . print_r($_SESSION, true));
error_log("Google Complete Profile - GET params: " . print_r($_GET, true));

// Check if OAuth data exists in session
if (!isset($_SESSION['google_oauth_data'])) {
    error_log("Google Complete Profile Error: google_oauth_data not found in session");
    error_log("Available session keys: " . implode(', ', array_keys($_SESSION)));
    header('Location: ' . $basePath . '/auth/login.php?error=' . urlencode('Please sign in with Google first. Session may have expired.'));
    exit;
}

$oauthData = $_SESSION['google_oauth_data'];
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_profile'])) {
    require_once __DIR__ . '/../app/handlers/google_complete_profile.php';
    $result = handleGoogleCompleteProfile();

    if ($result['success']) {
        // Clear OAuth data from session
        unset($_SESSION['google_oauth_data']);

        // Log user in
        loginUser($result['user_id'], $oauthData['email']);

        // Save any guest cart to database for the new user
        if (isset($_SESSION['cart']) && !empty($_SESSION['cart'])) {
            require_once __DIR__ . '/../config/db.php';

            // Save cart to database directly (using the same logic as cart.php)
            global $pdo;
            if ($pdo && $result['user_id']) {
                try {
                    $pdo->beginTransaction();

                    // Delete existing cart items for this user
                    $deleteStmt = $pdo->prepare("DELETE FROM cart_items WHERE user_id = ?");
                    $deleteStmt->execute([$result['user_id']]);

                    // Insert current cart items
                    $insertStmt = $pdo->prepare("
                        INSERT INTO cart_items (user_id, item_id, item_name, item_description, quantity, unit_price, item_image)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");

                    foreach ($_SESSION['cart'] as $item) {
                        $insertStmt->execute([
                            $result['user_id'],
                            $item['id'] ?? uniqid('item_', true),
                            $item['name'] ?? '',
                            $item['description'] ?? '',
                            intval($item['quantity'] ?? 1),
                            floatval($item['price'] ?? 0),
                            $item['image'] ?? 'assets/plate.png'
                        ]);
                    }

                    $pdo->commit();
                } catch (PDOException $e) {
                    error_log("Cart save error after Google registration: " . $e->getMessage());
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                }
            }
        }

        $next = $_GET['next'] ?? '/';
        header('Location: ' . $next);
        exit;
    } else {
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Complete Your Profile - JustKleek</title>
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
    </style>
    <?php require_once __DIR__ . '/../includes/meta_pixel.php'; ?>
</head>

<body>
    <div class="auth-container">
        <div class="auth-box">
            <h1 class="auth-title">Complete Your Profile</h1>
            <p class="auth-subtitle">We need a few more details to create your account</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo e($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="" id="completeProfileForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">

                <div class="form-group">
                    <label class="form-label" for="name">Full Name</label>
                    <input type="text" id="name" name="name" class="form-input"
                        value="<?php echo e($oauthData['name'] ?? ''); ?>" required autofocus>
                </div>

                <div class="form-group">
                    <label class="form-label" for="email">Email</label>
                    <input type="email" id="email" name="email" class="form-input"
                        value="<?php echo e($oauthData['email']); ?>" readonly>
                </div>

                <div class="form-group">
                    <label class="form-label" for="phone">Phone Number</label>
                    <input type="tel" id="phone" name="phone" class="form-input" required>
                </div>

                <button type="submit" name="complete_profile" class="btn-primary">Complete Registration</button>
            </form>
        </div>
    </div>
</body>

</html>