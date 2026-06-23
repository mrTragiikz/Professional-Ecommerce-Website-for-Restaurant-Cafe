<?php
/**
 * Email Verification Required Page
 * Shows a message and button to redirect to profile for verification
 */

require_once __DIR__ . '/../app/functions/security.php';
require_once __DIR__ . '/../app/functions/auth.php';

initSecureSession();

// Redirect if not logged in
if (!isUserLoggedIn()) {
    $basePath = getBasePath();
    header('Location: ' . $basePath . '/auth/login.php');
    exit;
}

$user = getCurrentUser();
$basePath = getBasePath();
$message = $_GET['message'] ?? 'Please verify your email address to continue.';
$redirectUrl = $_GET['redirect'] ?? '';

// Decode the redirect URL if it's URL-encoded
if (!empty($redirectUrl)) {
    $redirectUrl = urldecode($redirectUrl);
}

// Normalize the redirect URL for comparison (lowercase, remove query strings and fragments)
$normalizedRedirect = !empty($redirectUrl) ? strtolower($redirectUrl) : '';
$normalizedRedirect = preg_replace('/[?#].*$/', '', $normalizedRedirect); // Remove query string and fragment

// Determine the page name for "Go Back" button text and set proper redirect URL
$pageName = 'Home';
$finalRedirectUrl = $basePath . '/';

// Check which page the user was trying to access (case-insensitive, check for filename)
// Check both the normalized URL and the original URL for better detection
$checkUrl = ($normalizedRedirect ? $normalizedRedirect . ' ' : '') . strtolower($redirectUrl);

// Also check if the URL contains reservation-related keywords

if (preg_match('/menu(\.php)?/i', $checkUrl)) {
    $pageName = 'Menu';
    $finalRedirectUrl = $basePath . '/menu';
} elseif (preg_match('/cart(\.php)?/i', $checkUrl)) {
    $pageName = 'Cart';
    $finalRedirectUrl = $basePath . '/cart';
} elseif (preg_match('/basket(\.php)?/i', $checkUrl)) {
    $pageName = 'Cart';
    $finalRedirectUrl = $basePath . '/basket';
} elseif (preg_match('/(track-order|order[-_]?tracking(\.php)?)/i', $checkUrl)) {
    $pageName = 'Track Order';
    $finalRedirectUrl = $basePath . '/track-order';
} elseif (!empty($redirectUrl)) {
    // If redirect URL is provided but doesn't match known pages, use it directly
    // Remove base path if it's duplicated
    if ($basePath && strpos($redirectUrl, $basePath) === 0) {
        $finalRedirectUrl = $redirectUrl;
    } elseif (strpos($redirectUrl, '/') === 0) {
        $finalRedirectUrl = $basePath . $redirectUrl;
    } elseif (strpos($redirectUrl, 'http') === 0) {
        $finalRedirectUrl = $redirectUrl;
    } else {
        $finalRedirectUrl = $basePath . '/' . ltrim($redirectUrl, '/');
    }
}

// Ensure finalRedirectUrl doesn't have double slashes (except after http:// or https://)
$finalRedirectUrl = preg_replace('#([^:])//+#', '$1/', $finalRedirectUrl);

// Final safety check: if finalRedirectUrl is empty or just a slash, default to home
if (empty($finalRedirectUrl) || $finalRedirectUrl === '/' || $finalRedirectUrl === $basePath . '/') {
    $finalRedirectUrl = $basePath . '/';
    $pageName = 'Home';
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Email Verification Required - JustKleek</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link href="https://fonts.bunny.net/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/style.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .verification-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 380px;
            width: 100%;
            padding: 32px 24px;
            text-align: center;
            position: relative;
        }

        .verification-icon {
            width: 52px;
            height: 52px;
            margin: 0 auto 16px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .verification-icon svg {
            width: 26px;
            height: 26px;
            color: white;
        }

        .verification-title {
            font-size: 20px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
            letter-spacing: -0.3px;
        }

        .verification-message {
            font-size: 13px;
            color: #64748b;
            line-height: 1.5;
            margin-bottom: 24px;
        }

        .verification-email {
            font-weight: 600;
            color: #667eea;
            word-break: break-all;
            font-size: 12px;
            text-decoration: underline;
            display: inline-block;
            margin-top: 4px;
        }

        .btn-verify {
            width: 100%;
            padding: 14px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            margin-bottom: 12px;
        }

        .btn-verify:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4);
        }

        .btn-verify:active {
            transform: translateY(0);
        }

        .btn-verify svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }

        .btn-back {
            margin-top: 0;
            padding: 12px 20px;
            background: white;
            color: #4a5568;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-block;
            width: 100%;
            text-align: center;
            -webkit-tap-highlight-color: transparent;
            touch-action: manipulation;
        }

        .btn-back:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
        }

        .btn-back:active {
            background: #edf2f7;
            transform: scale(0.98);
        }

        @media (max-width: 600px) {
            .verification-container {
                padding: 28px 20px;
                max-width: 100%;
                border-radius: 14px;
            }

            .verification-title {
                font-size: 18px;
            }

            .verification-message {
                font-size: 12px;
                margin-bottom: 20px;
            }

            .verification-icon {
                width: 48px;
                height: 48px;
                margin-bottom: 14px;
            }

            .verification-icon svg {
                width: 24px;
                height: 24px;
            }

            .verification-email {
                font-size: 11px;
            }

            .btn-verify {
                padding: 12px 18px;
                font-size: 13px;
            }

            .btn-back {
                padding: 11px 18px;
                font-size: 12px;
            }
        }
    </style>
    <script src="<?php echo $basePath; ?>/js/prevent-zoom.js"></script>
    <script>
        // Debug and ensure back button works
        document.addEventListener('DOMContentLoaded', function () {
            const backButton = document.getElementById('backButton');
            if (backButton) {
                const href = backButton.getAttribute('href');
                console.log('Back button href:', href);
                console.log('Base path:', '<?php echo $basePath; ?>');
                console.log('Redirect URL:', '<?php echo htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8'); ?>');
                console.log('Final redirect URL:', '<?php echo htmlspecialchars($finalRedirectUrl, ENT_QUOTES, 'UTF-8'); ?>');

                // Ensure button is clickable and href is valid
                if (!href || href === '#' || href === '') {
                    console.error('Invalid href detected, setting default');
                    backButton.href = '<?php echo $basePath; ?>/menu';
                }
            }
        });
    </script>
    <?php require_once __DIR__ . '/../includes/meta_pixel.php'; ?>
</head>

<body>
    <div class="verification-container">
        <div class="verification-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
                <polyline points="22,6 12,13 2,6" />
            </svg>
        </div>

        <h1 class="verification-title">Email Verification Required</h1>

        <p class="verification-message">
            <?php echo htmlspecialchars($message); ?>
            <br><br>
            Your email: <span class="verification-email"><?php echo htmlspecialchars($user['email'] ?? ''); ?></span>
        </p>

        <a href="<?php echo $basePath; ?>/profile" class="btn-verify">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
                <polyline points="22,6 12,13 2,6" />
            </svg>
            Verify Email
        </a>

        <a href="<?php echo htmlspecialchars($finalRedirectUrl); ?>" class="btn-back" id="backButton">Back to
            <?php echo htmlspecialchars($pageName); ?></a>
    </div>
</body>

</html>
