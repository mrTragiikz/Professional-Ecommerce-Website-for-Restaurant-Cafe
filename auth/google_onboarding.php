<?php
/**
 * Google Onboarding Page - Profile Completion for First-Time Google Users
 * Form with first_name, last_name, phone, suburb (email read-only from Google)
 */

require_once __DIR__ . '/../app/functions/security.php';
require_once __DIR__ . '/../app/functions/auth.php';

initSecureSession();
setSecurityHeaders();

// Get base path for URLs
$basePath = getBasePath();

// Redirect if already logged in
if (isUserLoggedIn()) {
    $next = $_GET['next'] ?? $basePath . '/';
    header('Location: ' . $next);
    exit;
}

// Check if OAuth data exists in session
if (!isset($_SESSION['google_oauth_data'])) {
    error_log("Google Onboarding Error: google_oauth_data not found in session");
    header('Location: ' . $basePath . '/auth/login.php?error=' . urlencode('Please sign in with Google first. Session may have expired.'));
    exit;
}

$oauthData = $_SESSION['google_oauth_data'];
$error = '';

// Parse Google name into first_name and last_name (simple split on first space)
$firstName = '';
$lastName = '';
if (!empty($oauthData['name'])) {
    $nameParts = explode(' ', trim($oauthData['name']), 2);
    $firstName = $nameParts[0] ?? '';
    $lastName = $nameParts[1] ?? '';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_profile'])) {
    require_once __DIR__ . '/../app/handlers/google_onboarding_submit.php';
    $result = handleGoogleOnboardingSubmit();

    if ($result['success']) {
        // Clear OAuth data from session
        unset($_SESSION['google_oauth_data']);

        // Log user in
        loginUser($result['user_id'], $oauthData['email']);

        // Save any guest cart to database for the new user
        if (isset($_SESSION['cart']) && !empty($_SESSION['cart'])) {
            require_once __DIR__ . '/../config/db.php';

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
                    error_log("Cart save error after Google onboarding: " . $e->getMessage());
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                }
            }
        }

        $next = $_GET['next'] ?? '/';

        // Fix for double base path
        if ($basePath && strpos($next, $basePath) === 0) {
            header('Location: ' . $next);
        } else {
            header('Location: ' . $basePath . $next);
        }
        exit;
    } else {
        $error = $result['error'];
    }
}

// Note: e() function is defined in app/functions/security.php
// No need to redeclare it here

// Fetch active branches
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
    <title>Complete Your Profile - JustKleek</title>
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

        .phone-input-wrapper:focus-within {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
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

        .custom-dropdown {
            position: relative;
            width: 100%;
        }

        .dropdown-input-wrapper {
            position: relative;
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
        }

        .dropdown-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
        }

        .dropdown-arrow {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            transition: transform 0.3s;
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
            border: 2px solid #e0e0e0;
            border-top: none;
            border-radius: 0 0 10px 10px;
            max-height: 400px;
            z-index: 3000;
            display: none;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.14);
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

        /* Custom Modal Styles */
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
    </style>
    <?php require_once __DIR__ . '/../includes/meta_pixel.php'; ?>
</head>
<body>
<div class="auth-container"><div class="auth-box"><h1 class="auth-title">Complete Your Profile</h1><p class="auth-subtitle">We need a few more details to create your account</p>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo e($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="" id="completeProfileForm"><input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>"><div class="form-group form-group-row"><div><label class="form-label" for="first_name">First Name *</label><input type="text" id="first_name" name="first_name" class="form-input"
        value="<?php echo e($firstName); ?>" required autofocus></div><div><label class="form-label" for="last_name">Last Name *</label><input type="text" id="last_name" name="last_name" class="form-input"
        value="<?php echo e($lastName); ?>" required></div></div><div class="form-group"><label class="form-label" for="email">Email *</label><input type="email" id="email" name="email" class="form-input"
        value="<?php echo e($oauthData['email']); ?>" required readonly></div><div class="form-group"><label class="form-label" for="phone">Phone Number *</label><div class="phone-input-wrapper"
        style="display: flex; align-items: center; border: 1.5px solid #e5e7eb; border-radius: 10px; background: white; transition: all 0.2s ease;"><span class="phone-country-code"
        style="padding: 11px 16px; background: #f7fafc; border-right: 1.5px solid #e5e7eb; color: #1a202c; font-weight: 600; font-size: 16px; user-select: none; flex-shrink: 0; border-radius: 10px 0 0 10px;">+977</span><input type="tel" id="phone" name="phone" class="phone-input"
        placeholder="Enter your phone number" pattern="[0-9]{10}" maxlength="10" required style="flex: 1; border: none; padding: 11px 16px; font-size: 15px; background: transparent; color: #1a202c; outline: none; font-family: inherit;"></div></div><div class="form-group">
    <label class="form-label" for="branch_id">Select Branch *</label>
    <select id="branch_id" name="branch_id" class="form-input" required>
        <option value="" disabled selected>Select your branch</option>
        <?php foreach ($activeBranches ?? [] as $branch): ?>
            <option value="<?php echo (int)$branch['id']; ?>"><?php echo htmlspecialchars((string)$branch['name']); ?></option>
        <?php endforeach; ?>
    </select>
</div>

<div class="form-group">
    <label class="form-label" for="delivery_location">Delivery Location *</label>
<select id="delivery_location" name="delivery_location_select" class="form-input" required onchange="toggleManualLocation('delivery_location', 'manualLocationContainer')"><option value="" disabled selected>Select Area</option><option value="Bharatpur-11">Bharatpur-11</option><option value="Bharatpur-10">Bharatpur-10</option><option value="Bharatpur-12">Bharatpur-12</option><option value="Bharatpur-9">Bharatpur-9</option><option value="Rampur">Rampur</option><option value="Sauraha">Sauraha</option><option value="Other">Other</option></select></div><div class="form-group" id="manualLocationContainer" style="display: none; margin-top: 10px;"><label class="form-label" for="manual_delivery_location">Manually Enter Location *</label><input type="text" id="manual_delivery_location" name="manual_delivery_location" class="form-input" placeholder="Type your area name"></div><div class="form-group"><label class="form-label" for="street_location">Street Location *</label><div style="display: flex; gap: 8px; align-items: center;"><input type="text" id="street_location" name="street_location" class="form-input"
        placeholder="Please click 'Use Current Location'" readonly required><button type="button" class="btn-secondary use-current-location-btn" style="flex-shrink: 0; padding: 11px 14px; width: auto; font-size: 13px;" onmouseover="this.style.color='#4b5563';" onmouseout="this.style.color='';">Use Current Location</button><input type="hidden" id="location_lat" name="location_lat"><input type="hidden" id="location_lng" name="location_lng"></div></div><div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 24px;"><button type="submit" name="complete_profile" class="btn-primary"
        style="display: flex; align-items: center; justify-content: center;">Complete</button><a href="<?php echo $basePath; ?>/auth/login.php" class="btn-secondary">Back</a></div></form></div></div>

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
<script>const basePath='<?php echo $basePath; ?>';

        // Toggle manual location input
        function toggleManualLocation(selectId, containerId) {
            const select=document.getElementById(selectId);
            const container=document.getElementById(containerId);

            if (select && container) {
                const manualInput=container.querySelector('input');
                if (select.value==='Other') {
                    container.style.display='block';
                    if (manualInput) {
                        manualInput.required = true;
                        manualInput.focus();
                    }
                } else {
                    container.style.display='none';
                    if (manualInput) {
                        manualInput.required = false;
                        manualInput.value = '';
                    }
                }
            }
        }

        // Initialize on load
        document.addEventListener('DOMContentLoaded', function() {
                toggleManualLocation('delivery_location', 'manualLocationContainer');
            });

        // Phone number validation - only allow digits
        (function () {
                const phoneInput=document.getElementById('phone');

                if (phoneInput) {
                    phoneInput.addEventListener('input', function (e) {
                            // Remove any non-digit characters
                            this.value=this.value.replace(/[^0-9]/g, '');
                        });

                    phoneInput.addEventListener('paste', function (e) {
                            e.preventDefault();
                            const paste=(e.clipboardData || window.clipboardData).getData('text');
                            const digitsOnly=paste.replace(/[^0-9]/g, '');
                            this.value=digitsOnly;
                        });
                }
            })();

        // Format phone number with +977 prefix before form submission
        document.addEventListener('DOMContentLoaded', function() {
                const form=document.getElementById('completeProfileForm');
                const phoneInput=document.getElementById('phone');

                // Handle phone and delivery location before submission
                form.addEventListener('submit', function (e) {
                        const phoneValue=phoneInput.value.replace(/[^0-9]/g, '');

                        if (phoneValue.length !==10) {
                            e.preventDefault();
                            showCustomAlert('Invalid Phone', 'Please enter a valid phone number (10 digits)');
                            phoneInput.focus();
                            return;
                        }

                        // Remove the original phone input name to prevent double submission
                        phoneInput.removeAttribute('name');

                        // Add hidden phone field with +977 prefix
                        const phoneField=document.createElement('input');
                        phoneField.type='hidden';
                        phoneField.name='phone';
                        phoneField.value='+977' + phoneValue;
                        form.appendChild(phoneField);

                        // Handle delivery location
                        const deliverySelect=document.getElementById('delivery_location');
                        const manualInput=document.getElementById('manual_delivery_location');
                        const finalValue=(deliverySelect.value==='Other') ? manualInput.value.trim() : deliverySelect.value;
                        
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
                            showCustomAlert('Street Required', 'Please provide your street location (click "Use Current Location")');
                            return;
                        }

                        // Add hidden field for delivery_location
                        const deliveryField=document.createElement('input');
                        deliveryField.type='hidden';
                        deliveryField.name='delivery_location';
                        deliveryField.value=finalValue;
                        form.appendChild(deliveryField);
                    });

                // Geolocation logic
                document.querySelectorAll('.use-current-location-btn').forEach(btn=> {
                        btn.addEventListener('click', function() {
                                if ( !navigator.geolocation) return;
                                const originalText=this.textContent;
                                this.textContent="Getting...";
                                this.disabled=true;

                                navigator.geolocation.getCurrentPosition(pos=> {
                                        this.textContent=originalText;
                                        this.disabled=false;
                                        document.getElementById('location_lat').value=pos.coords.latitude;
                                        document.getElementById('location_lng').value=pos.coords.longitude;
                                        const streetInput=document.getElementById('street_location');

                                        if (streetInput) {
                                            streetInput.value = `${pos.coords.latitude.toFixed(5)}, ${pos.coords.longitude.toFixed(5)}`;
                                            streetInput.dispatchEvent(new Event('input'));
                                        }
                                    }

                                    , err=> {
                                        this.textContent=originalText;
                                        this.disabled=false;
                                    });
                            });
                    });
            });
        </script></body></html>