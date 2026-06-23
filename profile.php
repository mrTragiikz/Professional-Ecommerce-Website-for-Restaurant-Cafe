<?php
/**
 * User Profile Page
 * Shows user details and logout option
 */

// Security initialization (must be first)
require_once __DIR__ . '/app/functions/security_init.php';

require_once __DIR__ . '/app/functions/auth.php';

// Redirect if not logged in
if (!isUserLoggedIn()) {
    $basePath = getBasePath();
    header('Location: ' . $basePath . '/auth/login.php');
    exit;
}

$user = getCurrentUser();
$basePath = getBasePath();

if (!$user) {
    header('Location: ' . $basePath . '/auth/logout.php');
    exit;
}

require_once __DIR__ . '/config/mapbox.php';
require_once __DIR__ . '/config/db.php';

// Get profile picture - prioritize uploaded picture, then Google/Gravatar
$profilePicture = null;
if (!empty($user['profile_picture']) && file_exists(__DIR__ . '/' . $user['profile_picture'])) {
    $profilePicture = $basePath . '/' . $user['profile_picture'];
} elseif (!empty($user['google_id'])) {
    // Use Gravatar for Google users
    $profilePicture = 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($user['email']))) . '?d=identicon&s=200';
}

// If no picture, use initials
$initials = '';
if (!empty($user['name'])) {
    $nameParts = explode(' ', $user['name']);
    $initials = strtoupper(substr($nameParts[0], 0, 1));
    if (count($nameParts) > 1) {
        $initials .= strtoupper(substr($nameParts[count($nameParts) - 1], 0, 1));
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>My Profile - JustKleek</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link href="https://fonts.bunny.net/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/style.css?v=1.0.1">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/assets/css/dv_mobile_nav_v3.css?v=<?php echo time(); ?>">
    <style>
        :root {
            --primary: #667eea;
            --primary-dark: #5568d3;
            --secondary: #764ba2;
            --danger: #e53e3e;
            --danger-dark: #c53030;
            --text-dark: #1a202c;
            --text-gray: #718096;
            --text-light: #a0aec0;
            --bg-gray: #f7fafc;
            --bg-hover: #edf2f7;
            --border: #e2e8f0;
            --success: #22543d;
            --success-bg: #d1f2eb;
            --error: #721c24;
            --error-bg: #f8d7da;
            --shadow-sm: 0 2px 4px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 30px rgba(0, 0, 0, 0.15);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
            padding: 24px;
        }

        .profile-container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 40px 30px 35px;
            text-align: center;
            color: white;
            position: relative;
        }

        .profile-avatar-wrapper {
            position: relative;
            display: inline-block;
            margin-bottom: 16px;
        }

        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: 3px solid white;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            position: relative;
        }

        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-avatar .initials {
            font-size: 28px;
            font-weight: 600;
            color: #667eea;
            background: #f0f4ff;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .avatar-upload-btn {
            position: absolute;
            bottom: 0;
            right: 0;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #667eea;
            border: 2px solid white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
        }

        .avatar-upload-btn:hover {
            background: #5568d3;
            transform: scale(1.1);
        }

        .avatar-upload-btn svg {
            width: 14px;
            height: 14px;
            color: white;
        }

        .profile-name {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 6px;
            letter-spacing: -0.2px;
        }

        .profile-email {
            font-size: 14px;
            opacity: 0.9;
            font-weight: 400;
        }

        .profile-content {
            padding: 28px 32px;
            background: white;
        }

        .profile-section {
            margin-bottom: 24px;
        }

        .section-title {
            font-size: 16px;
            font-weight: 700;
            color: #000000;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin: 0 0 14px 0;
            padding: 0;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .info-item {
            background: #f8f9fa;
            padding: 16px 18px;
            border-radius: 10px;
            border: 1px solid #e9ecef;
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .info-item:hover {
            background: #f1f3f5;
            border-color: #dee2e6;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .info-label {
            font-size: 11px;
            font-weight: 600;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0;
        }

        .info-value {
            font-size: 16px;
            font-weight: 500;
            color: #212529;
            word-break: break-word;
            line-height: 1.5;
        }

        .info-value.empty {
            color: #adb5bd;
            font-style: italic;
            font-weight: 400;
        }

        .verification-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 8px;
        }

        .verification-badge.verified {
            background: var(--success-bg);
            color: var(--success);
        }

        .verification-badge.unverified {
            background: var(--error-bg);
            color: var(--error);
        }

        .verification-badge svg {
            width: 13px;
            height: 13px;
        }

        .verification-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 8px;
            flex-wrap: wrap;
            position: relative;
            z-index: 1;
        }

        .btn-verify-email {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            z-index: 1;
            pointer-events: auto;
            -webkit-tap-highlight-color: transparent;
            touch-action: manipulation;
        }

        .btn-verify-email:hover:not(:disabled) {
            background: #5568d3;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
            text-decoration: none;
            color: white;
        }

        .btn-verify-email:active:not(:disabled) {
            transform: translateY(0);
            box-shadow: 0 2px 6px rgba(102, 126, 234, 0.3);
        }

        .btn-verify-email:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            pointer-events: none;
        }

        .btn-verify-email svg {
            width: 14px;
            height: 14px;
            flex-shrink: 0;
            pointer-events: none;
        }

        .action-buttons {
            display: flex;
            flex-direction: row;
            gap: 12px;
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: none;
            flex: 1;
            max-width: 240px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #5568d3 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.25);
        }

        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.35);
        }

        .btn-danger {
            background: #e53e3e;
            color: white;
            box-shadow: 0 2px 8px rgba(229, 62, 62, 0.25);
        }

        .btn-danger:hover {
            background: #c53030;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(229, 62, 62, 0.35);
        }

        .btn-secondary {
            background: white;
            color: #667eea;
            border: 2px solid #667eea;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
        }

        .btn-secondary:hover {
            background: #f8f9ff;
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(102, 126, 234, 0.15);
        }

        .btn svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }


        /* Edit Modal */
        .edit-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .edit-modal.active {
            display: flex;
        }

        .edit-modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 1001;
            touch-action: none;
            overflow: hidden;
        }

        .edit-modal-content {
            position: relative;
            background: white;
            border-radius: 12px;
            padding: 20px;
            max-width: 500px;
            width: 100%;
            max-height: calc(100vh - 40px);
            margin: auto;
            overflow-y: auto;
            overflow-x: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            z-index: 1002;
            transform: translateZ(0);
        }

        .edit-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e9ecef;
        }

        .edit-modal-title {
            font-size: 18px;
            font-weight: 600;
            color: #1a202c;
        }

        .edit-modal-close {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: none;
            background: #f1f3f5;
            color: #718096;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            transition: all 0.2s ease;
        }

        .edit-modal-close:hover {
            background: #e9ecef;
            color: #1a202c;
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: #4a5568;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-input {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            transition: all 0.2s ease;
            background: white;
        }

        .form-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .phone-input-wrapper:focus-within {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .phone-input::placeholder {
            color: #a0aec0;
        }

        .form-textarea {
            min-height: 80px;
            resize: vertical;
        }

        .location-actions {
            display: flex;
            gap: 8px;
            margin-top: 8px;
        }

        .location-btn {
            flex: 1;
            padding: 8px 14px;
            border: 2px solid #667eea;
            border-radius: 8px;
            background: white;
            color: #667eea;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
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

        /* Responsive Fix for Pinned Location */
        @media (max-width: 600px) {
            .info-item.profile-location {
                flex-direction: column !important;
                align-items: flex-start !important;
                gap: 12px !important;
            }

            .info-item.profile-location button {
                width: 100% !important;
                margin-top: 8px !important;
            }

            .info-item.profile-location .info-value {
                width: 100%;
            }

            .info-item.profile-location p {
                white-space: normal !important;
            }
        }

        .location-btn svg {
            width: 16px;
            height: 16px;
        }

        .profile-picture-edit {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .current-picture-preview {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            border: 2px solid #e2e8f0;
            overflow: visible;
            position: relative;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .current-picture-preview img {
            border-radius: 50%;
            overflow: hidden;
        }

        .current-picture-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .no-picture-placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #a0aec0;
            width: 100%;
            height: 100%;
        }

        .no-picture-placeholder svg {
            width: 28px;
            height: 28px;
        }

        .no-picture-placeholder span {
            font-size: 10px;
        }

        .remove-picture-btn {
            position: absolute;
            top: -8px;
            right: -8px;
            width: 28px;
            height: 28px;
            padding: 0;
            background: #ef4444;
            color: white;
            border: 2px solid white;
            border-radius: 50%;
            font-size: 0;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            z-index: 10;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }

        .remove-picture-btn:hover {
            background: #dc2626;
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
        }

        .remove-picture-btn:active {
            transform: scale(0.95);
        }

        .remove-picture-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .remove-picture-btn svg {
            width: 14px;
            height: 14px;
            flex-shrink: 0;
        }

        .change-picture-btn {
            padding: 8px 14px;
            border: 2px solid #667eea;
            border-radius: 8px;
            background: white;
            color: #667eea;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            width: fit-content;
        }

        .change-picture-btn:hover {
            background: #f8f9ff;
            border-color: #5568d3;
        }

        .change-picture-btn svg {
            width: 14px;
            height: 14px;
        }

        .name-picture-row {
            display: flex;
            gap: 16px;
            align-items: flex-start;
        }

        .name-picture-row .form-group {
            flex: 1;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #e9ecef;
        }

        .modal-actions .btn {
            flex: 1;
            max-width: none;
            padding: 10px 18px;
            font-size: 13px;
        }

        /* Mobile Responsive */
        @media (max-width: 768px) {
            body {
                padding: 0;
                background: #f0f2f5;
            }

            .profile-container {
                margin: 0;
                border-radius: 0;
            }

            .profile-header {
                padding: 50px 20px 40px;
            }

            .profile-avatar {
                width: 90px;
                height: 90px;
            }

            .profile-avatar .initials {
                font-size: 32px;
            }

            .profile-name {
                font-size: 22px;
            }

            .profile-email {
                font-size: 13px;
            }

            .profile-content {
                padding: 30px 20px;
            }

            .info-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .verification-actions {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }

            .btn-verify-email {
                width: 100%;
                justify-content: center;
                padding: 10px 16px;
                font-size: 14px;
            }

            .action-buttons {
                flex-direction: column;
                gap: 10px;
            }

            .btn {
                max-width: none;
            }

            .edit-modal {
                padding: 10px;
                align-items: flex-start;
                padding-top: 90px;
            }

            .edit-modal-content {
                padding: 18px 14px;
                max-width: 100%;
                max-height: calc(100vh - 100px);
                margin: 0;
            }

            .name-picture-row {
                flex-direction: column;
                gap: 12px;
            }

            .profile-picture-edit {
                flex-direction: row;
                align-items: center;
            }

            .current-picture-preview {
                width: 60px;
                height: 60px;
            }

            .remove-picture-btn {
                top: -6px;
                right: -6px;
                width: 24px;
                height: 24px;
                border-width: 2px;
            }

            .remove-picture-btn svg {
                width: 12px;
                height: 12px;
            }

            .form-input {
                padding: 10px 12px;
                font-size: 14px;
            }

            .location-btn {
                padding: 9px 12px;
                font-size: 12px;
            }
        }

        /* OTP Verification Modal - Compact & Modern */
        .otp-modal-profile {
            position: fixed;
            inset: 0;
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 12px;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1), visibility 0.3s ease;
        }

        .otp-modal-profile.active {
            display: flex;
            opacity: 1;
            visibility: visible;
        }

        .otp-modal-profile-backdrop {
            position: absolute;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .otp-modal-profile.active .otp-modal-profile-backdrop {
            opacity: 1;
        }

        .otp-modal-profile-content {
            position: relative;
            background: white;
            border-radius: 16px;
            padding: 24px;
            max-width: 380px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            transform: scale(0.9) translateY(20px);
            opacity: 0;
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.3s ease;
            -webkit-overflow-scrolling: touch;
        }

        .otp-modal-profile.active .otp-modal-profile-content {
            transform: scale(1) translateY(0);
            opacity: 1;
        }

        .otp-modal-profile-header {
            text-align: center;
            margin-bottom: 16px;
        }

        .otp-modal-profile-icon {
            width: 52px;
            height: 52px;
            margin: 0 auto 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: iconPopIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) 0.2s both;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        @keyframes iconPopIn {
            0% {
                transform: scale(0);
                opacity: 0;
            }

            50% {
                transform: scale(1.1);
            }

            100% {
                transform: scale(1);
                opacity: 1;
            }
        }

        .otp-modal-profile-icon svg {
            width: 26px;
            height: 26px;
            color: white;
        }

        .otp-modal-profile-title {
            font-size: 24px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
            letter-spacing: -0.3px;
            animation: fadeInUp 0.4s ease 0.3s both;
        }

        .otp-modal-profile-subtitle {
            font-size: 15px;
            color: #64748b;
            line-height: 1.6;
            margin-bottom: 4px;
            animation: fadeInUp 0.4s ease 0.35s both;
        }

        .otp-modal-profile-email {
            font-weight: 600;
            color: #667eea;
            font-size: 14px;
            word-break: break-all;
            text-decoration: underline;
            display: inline-block;
            margin-top: 6px;
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

        .otp-modal-profile-input-container {
            margin: 14px 0;
        }

        .otp-modal-profile-input-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #718096;
            margin-bottom: 10px;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .otp-modal-profile-input {
            width: 100%;
            height: 56px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 28px;
            font-weight: 700;
            text-align: center;
            color: #667eea;
            font-family: 'Courier New', monospace;
            letter-spacing: 10px;
            padding: 0 16px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: #f8f9fa;
            animation: inputFadeIn 0.4s ease 0.4s both;
        }

        @keyframes inputFadeIn {
            from {
                opacity: 0;
                transform: translateY(5px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .otp-modal-profile-input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.15);
            transform: scale(1.01);
        }

        .otp-modal-profile-input:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .otp-modal-profile-hint {
            text-align: center;
            font-size: 13px;
            color: #94a3b8;
            margin-top: 8px;
            font-weight: 500;
        }

        .otp-modal-profile-error,
        .otp-modal-profile-success {
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 13px;
            margin-bottom: 14px;
            text-align: center;
            display: none;
            animation: slideDown 0.3s ease;
            font-weight: 500;
        }

        .otp-modal-profile-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .otp-modal-profile-error[style*="display: block"],
        .otp-modal-profile-error:not([style*="display: none"]) {
            display: block !important;
            animation: shake 0.4s ease, slideDown 0.3s ease;
        }

        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            10%,
            30%,
            50%,
            70%,
            90% {
                transform: translateX(-5px);
            }

            20%,
            40%,
            60%,
            80% {
                transform: translateX(5px);
            }
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
                max-height: 0;
            }

            to {
                opacity: 1;
                transform: translateY(0);
                max-height: 100px;
            }
        }

        .otp-modal-profile-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }

        .otp-modal-profile-success[style*="display: block"],
        .otp-modal-profile-success:not([style*="display: none"]) {
            display: block !important;
            animation: slideDown 0.3s ease, successPulse 0.6s ease 0.2s;
        }

        @keyframes successPulse {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.02);
            }
        }

        .otp-modal-profile-actions {
            display: flex;
            gap: 10px;
            margin-top: 18px;
        }

        .otp-modal-profile-btn {
            flex: 1;
            padding: 12px 20px;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-height: 46px;
        }

        .otp-modal-profile-btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 3px 10px rgba(102, 126, 234, 0.3);
        }

        .otp-modal-profile-btn-primary:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 5px 14px rgba(102, 126, 234, 0.4);
        }

        .otp-modal-profile-btn-primary:active:not(:disabled) {
            transform: translateY(0);
            box-shadow: 0 2px 6px rgba(102, 126, 234, 0.3);
        }

        .otp-modal-profile-btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .otp-modal-profile-btn-primary svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }

        .otp-modal-profile-btn-secondary {
            background: white;
            color: #4a5568;
            border: 2px solid #e2e8f0;
        }

        .otp-modal-profile-btn-secondary:hover {
            background: #f7fafc;
            border-color: #cbd5e0;
            transform: translateY(-1px);
        }

        .otp-modal-profile-btn-secondary:active {
            transform: translateY(0);
        }

        .otp-modal-profile-resend {
            text-align: center;
            margin-top: 14px;
            font-size: 13px;
            color: #64748b;
        }

        .otp-modal-profile-resend-link {
            color: #667eea;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            position: relative;
            font-size: 13px;
        }

        .otp-modal-profile-resend-link:hover {
            text-decoration: underline;
            color: #5568d3;
            transform: translateX(2px);
        }

        .otp-modal-profile-resend-link:active {
            transform: translateX(0);
        }

        .otp-modal-profile-back-to-profile {
            text-align: center;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
        }

        .otp-modal-profile-back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: #64748b;
            font-size: 13px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .otp-modal-profile-back-link:hover {
            color: #4a5568;
            transform: translateX(-2px);
        }

        .otp-modal-profile-back-link:active {
            transform: translateX(0);
        }

        .otp-modal-profile-back-link svg {
            width: 14px;
            height: 14px;
        }

        .otp-modal-profile-loading {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @media (max-width: 768px) {
            .otp-modal-profile {
                padding: 10px;
            }

            .otp-modal-profile-content {
                padding: 18px 16px;
                border-radius: 12px;
                max-width: 100%;
            }

            .otp-modal-profile-icon {
                width: 40px;
                height: 40px;
                margin-bottom: 8px;
            }

            .otp-modal-profile-icon svg {
                width: 20px;
                height: 20px;
            }

            .otp-modal-profile-title {
                font-size: 20px;
                margin-bottom: 6px;
            }

            .otp-modal-profile-subtitle {
                font-size: 14px;
            }

            .otp-modal-profile-email {
                font-size: 13px;
            }

            .otp-modal-profile-input-container {
                margin: 12px 0;
            }

            .otp-modal-profile-input {
                height: 48px;
                font-size: 24px;
                letter-spacing: 6px;
                padding: 0 10px;
            }

            .otp-modal-profile-actions {
                flex-direction: row;
                gap: 8px;
                margin-top: 16px;
            }

            .otp-modal-profile-btn {
                flex: 1;
                padding: 12px 18px;
                font-size: 14px;
                min-height: 44px;
            }

            .otp-modal-profile-resend {
                margin-top: 12px;
                font-size: 12px;
            }

            .otp-modal-profile-resend-link {
                font-size: 12px;
            }

            .otp-modal-profile-back-link {
                font-size: 12px;
            }

            .otp-modal-profile-input-label {
                font-size: 11px;
            }

            .otp-modal-profile-hint {
                font-size: 12px;
            }

            .otp-modal-profile-back-to-profile {
                margin-top: 8px;
                padding-top: 8px;
            }
        }

        @media (max-width: 480px) {
            .otp-modal-profile-content {
                padding: 16px 14px;
            }

            .otp-modal-profile-input {
                height: 46px;
                font-size: 22px;
                letter-spacing: 5px;
            }
        }
    </style>
    <script src="<?php echo $basePath; ?>/js/prevent-zoom.js"></script>
    <?php require_once __DIR__ . '/includes/meta_pixel.php'; ?>
</head>

<body class="home-page">
    <!-- Navigation Bar -->
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>

    <div class="profile-container">
        <div class="profile-header">
            <div class="profile-avatar-wrapper">
                <div class="profile-avatar">
                    <?php if ($profilePicture): ?>
                        <img src="<?php echo e($profilePicture); ?>" alt="<?php echo e($user['name']); ?>"
                            id="profileAvatarImg"
                            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="initials" id="profileAvatarInitials" style="display: none;"><?php echo e($initials); ?>
                        </div>
                    <?php else: ?>
                        <div class="initials" id="profileAvatarInitials"><?php echo e($initials); ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <h1 class="profile-name"><?php echo e($user['name']); ?></h1>
            <p class="profile-email"><?php echo e($user['email']); ?></p>
        </div>

        <div class="profile-content">
            <div class="profile-section">
                <h2 class="section-title">Account Information</h2>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Full Name</div>
                        <div class="info-value"><?php echo e($user['name']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Email Address</div>
                        <div class="info-value"><?php echo e($user['email']); ?></div>
                        <?php if ($user['is_verified']): ?>
                            <div class="verification-badge verified">
                                <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd"
                                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                        clip-rule="evenodd" />
                                </svg>
                                Verified
                            </div>
                        <?php else: ?>
                            <div class="verification-actions">
                                <div class="verification-badge unverified">
                                    <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                            clip-rule="evenodd" />
                                    </svg>
                                    Not Verified
                                </div>
                                <button type="button" class="btn-verify-email" id="verifyEmailBtn">
                                    <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor">
                                        <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" />
                                        <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" />
                                    </svg>
                                    Verify Email
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Phone Number</div>
                        <div class="info-value <?php echo empty($user['phone']) ? 'empty' : ''; ?>">
                            <?php echo e($user['phone'] ?: 'Not provided'); ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Delivery Location</div>
                        <div class="info-value <?php echo empty($user['delivery_location']) ? 'empty' : ''; ?>"
                            id="displayDeliveryLocation">
                            <?php echo e($user['delivery_location'] ?: 'Not provided'); ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Street Location</div>
                        <div class="info-value <?php echo empty($user['street_location']) ? 'empty' : ''; ?>"
                            id="displayStreetLocation">
                            <?php echo e($user['street_location'] ?: 'Not provided'); ?>
                        </div>
                    </div>


                    <div class="info-item">
                        <div class="info-label">Account Type</div>
                        <div class="info-value">
                            <?php
                            $hasGoogleId = !empty($user['google_id']);
                            $hasPassword = !empty($user['password_hash'] ?? null);

                            if ($hasGoogleId && $hasPassword): ?>
                                Email & Google
                            <?php elseif ($hasGoogleId): ?>
                                Google Account
                            <?php else: ?>
                                Email Account
                            <?php endif; ?>
                        </div>
                    </div>
                    <!-- Your Branch -->
                    <div class="info-item" style="border-left: 4px solid #f97316; background: #fffaf5;">
                        <div class="info-label" style="color: #f97316;">Your Branch</div>
                        <div class="info-value" style="font-weight: 800; color: #ea580c;">
                            <?php echo htmlspecialchars($user['branch_name'] ?: 'Not Assigned'); ?>
                            <div style="font-size: 11px; font-weight: 600; color: #94a3b8; margin-top: 2px; text-transform: none;">
                                Your account is tied to this location.
                            </div>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Member Since</div>
                        <div class="info-value">
                            <?php echo date('F j, Y', strtotime($user['created_at'])); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="action-buttons">
                <button type="button" class="btn btn-primary" id="editProfileBtn">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path
                            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Edit Profile
                </button>
                <a href="<?php echo $basePath; ?>/" class="btn btn-secondary">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path d="M3.33334 10L10 2.5L16.6667 10" stroke="currentColor" stroke-width="1.5"
                            stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M15 17.5H12.5V12.5H7.5V17.5H5V10H3.33334L10 3.33334L16.6667 10H15V17.5Z"
                            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Back to Home
                </a>
                <a href="<?php echo $basePath; ?>/auth/logout.php" class="btn btn-danger">
                    <svg width="20" height="20" viewBox="0 0 20 20" fill="none">
                        <path
                            d="M7.5 17.5H4.16667C3.24619 17.5 2.5 16.7538 2.5 15.8333V4.16667C2.5 3.24619 3.24619 2.5 4.16667 2.5H7.5"
                            stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M13.3333 14.1667L17.5 10L13.3333 5.83333" stroke="currentColor" stroke-width="1.5"
                            stroke-linecap="round" stroke-linejoin="round" />
                        <path d="M17.5 10H7.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                            stroke-linejoin="round" />
                    </svg>
                    Logout
                </a>
            </div>
        </div>
    </div>

    <!-- Edit Profile Modal -->
    <div class="edit-modal" id="editModal">
        <div class="edit-modal-backdrop" id="editModalBackdrop"></div>
        <div class="edit-modal-content">
            <div class="edit-modal-header">
                <h2 class="edit-modal-title">Edit Profile</h2>
                <button class="edit-modal-close" id="closeEditModal">&times;</button>
            </div>
            <form id="editProfileForm">

                <div class="name-picture-row">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label" for="editName">Full Name</label>
                        <input type="text" id="editName" name="name" class="form-input"
                            placeholder="Enter your full name" value="<?php echo e($user['name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group" style="margin-bottom: 0; flex-shrink: 0;">
                        <label class="form-label">Profile Picture</label>
                        <div class="profile-picture-edit">
                            <div class="current-picture-preview" id="currentPicturePreview">
                                <?php if ($profilePicture && !strpos($profilePicture, 'gravatar.com')): ?>
                                    <img src="<?php echo e($profilePicture); ?>" alt="Current" id="previewImg">
                                    <button type="button" class="remove-picture-btn" id="removePictureBtn"
                                        title="Delete profile picture">
                                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5">
                                            <path
                                                d="M3 6h14M8 6V4a2 2 0 012-2h0a2 2 0 012 2v2m3 0v10a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14zM10 11v6M14 11v6" />
                                        </svg>
                                    </button>
                                <?php else: ?>
                                    <div class="no-picture-placeholder">
                                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <path
                                                d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        <span>No picture</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <label for="editProfilePictureInput" class="change-picture-btn">
                                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path
                                        d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
                                <span><?php echo ($profilePicture && !strpos($profilePicture, 'gravatar.com')) ? 'Change' : 'Add'; ?></span>
                            </label>
                            <input type="file" id="editProfilePictureInput" accept="image/*" style="display: none;">
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="editPhone">Phone Number</label>
                    <div class="phone-input-wrapper"
                        style="display: flex; align-items: center; border: 2px solid #e2e8f0; border-radius: 8px; background: white; transition: all 0.2s ease;">
                        <span class="phone-country-code"
                            style="padding: 10px 12px; background: #f7fafc; border-right: 2px solid #e2e8f0; color: #1a202c; font-weight: 600; font-size: 14px; user-select: none; flex-shrink: 0;">+977</span>
                        <?php
                        // Extract phone number without +977 prefix for display
                        $phoneValue = $user['phone'] ?? '';
                        if (preg_match('/^\+977(.+)$/', $phoneValue, $matches)) {
                            $phoneValue = $matches[1];
                        } elseif (preg_match('/^61(.+)$/', $phoneValue, $matches)) {
                            $phoneValue = $matches[1];
                        }
                        ?>
                        <input type="tel" id="editPhone" name="phone" class="phone-input"
                            placeholder="Enter your phone number" value="<?php echo e($phoneValue); ?>"
                            pattern="[0-9]{10}" maxlength="10"
                            style="flex: 1; border: none; padding: 10px 14px; font-size: 14px; background: transparent; color: #1a202c; outline: none; font-family: inherit;">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="editDeliveryLocation">Delivery Location</label>
                    <select id="editDeliveryLocation" name="delivery_location_select" class="form-input"
                        onchange="toggleManualLocation('editDeliveryLocation', 'editManualLocationContainer')">
                        <?php
                        $predefined = ['Bharatpur - 11', 'Bharatpur - 10', 'Bharatpur - 12', 'Bharatpur - 9', 'Rampur', 'Sauraha'];
                        $currentLoc = $user['delivery_location'] ?? '';
                        $isOther = !empty($currentLoc) && !in_array($currentLoc, $predefined);
                        ?>
                        <option value="" disabled <?php echo empty($currentLoc) ? 'selected' : ''; ?>>Select Area
                        </option>
                        <?php foreach ($predefined as $p): ?>
                            <option value="<?php echo $p; ?>" <?php echo ($currentLoc === $p) ? 'selected' : ''; ?>>
                                <?php echo $p; ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="Other" <?php echo $isOther ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="form-group" id="editManualLocationContainer"
                    style="<?php echo $isOther ? 'display: block;' : 'display: none;'; ?> margin-top: 10px;">
                    <label class="form-label" for="editManualLocation">Manually Enter Location</label>
                    <input type="text" id="editManualLocation" name="manual_delivery_location" class="form-input"
                        placeholder="Type your area name" value="<?php echo $isOther ? e($currentLoc) : ''; ?>">
                </div>
                <div class="form-group">
                    <label class="form-label" for="editStreetLocation">Street Location</label>
                    <div style="display: flex; gap: 8px;align-items: center; flex-wrap: wrap;">
                        <input type="text" id="editStreetLocation" name="street_location" class="form-input"
                            placeholder="Please click 'Use Current Location'"
                            value="<?php echo e($user['street_location'] ?? ''); ?>" style="flex: 1; min-width: 200px;"
                            readonly>
                        <button type="button" class="btn btn-secondary use-current-location-btn"
                            style="flex-shrink: 0; padding: 10px 14px; width: auto; font-size: 13px; display: inline-flex;"
                            onmouseover="this.style.color='#667eea';" onmouseout="this.style.color='';">Use
                            Current Location</button>
                        <input type="hidden" id="editLocationLat" name="location_lat"
                            value="<?php echo e($user['location_lat'] ?? ''); ?>">
                        <input type="hidden" id="editLocationLng" name="location_lng"
                            value="<?php echo e($user['location_lng'] ?? ''); ?>">
                    </div>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" id="cancelEditBtn">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="confirmation-modal" id="confirmationModal">
        <div class="confirmation-backdrop"></div>
        <div class="confirmation-content">
            <div class="confirmation-icon" id="confirmationIcon"></div>
            <h3 class="confirmation-title" id="confirmationTitle">Confirm Action</h3>
            <p class="confirmation-message" id="confirmationMessage">Are you sure you want to proceed?
            </p>
            <div class="confirmation-actions">
                <button type="button" class="confirmation-btn confirmation-btn-cancel"
                    id="confirmationCancel">Cancel</button>
                <button type="button" class="confirmation-btn confirmation-btn-confirm"
                    id="confirmationConfirm">Confirm</button>
            </div>
        </div>
    </div>



    <!-- OTP Verification Modal -->
    <div class="otp-modal-profile" id="otpModalProfile">
        <div class="otp-modal-profile-backdrop"></div>
        <div class="otp-modal-profile-content">
            <div class="otp-modal-profile-header">
                <div class="otp-modal-profile-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
                        <polyline points="22,6 12,13 2,6" />
                    </svg>
                </div>
                <h2 class="otp-modal-profile-title">Verify Your Email</h2>
                <p class="otp-modal-profile-subtitle">
                    We've sent a 6-digit verification code to<br>
                    <span class="otp-modal-profile-email"
                        id="otpModalEmail"><?php echo htmlspecialchars($user['email']); ?></span>
                </p>
            </div>
            <div class="otp-modal-profile-error" id="otpModalError"></div>
            <div class="otp-modal-profile-success" id="otpModalSuccess">Email verified successfully!
            </div>
            <div class="otp-modal-profile-input-container">
                <label class="otp-modal-profile-input-label">Enter Verification Code</label>
                <input type="text" id="otpModalInput" class="otp-modal-profile-input" placeholder="000000" maxlength="6"
                    autocomplete="off" inputmode="numeric" pattern="[0-9]*">
                <p class="otp-modal-profile-hint">Enter the 6-digit code sent to your email</p>
            </div>
            <div class="otp-modal-profile-actions">
                <button type="button" class="otp-modal-profile-btn otp-modal-profile-btn-primary"
                    id="otpModalVerifyBtn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke-linecap="round" stroke-linejoin="round" />
                        <polyline points="22 4 12 14.01 9 11.01" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Verify
                </button>
                <button type="button" class="otp-modal-profile-btn otp-modal-profile-btn-secondary"
                    id="otpModalCancelBtn">
                    Cancel
                </button>
            </div>
            <div class="otp-modal-profile-resend">
                Didn't receive the code?
                <a href="#" class="otp-modal-profile-resend-link" id="otpModalResendLink">Resend</a>
            </div>
            <div class="otp-modal-profile-back-to-profile">
                <a href="<?php echo $basePath; ?>/profile" class="otp-modal-profile-back-link">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 12H5M12 19l-7-7 7-7" />
                    </svg>
                    Back to Profile
                </a>
            </div>
        </div>
    </div>

    <style>
        /* Confirmation Modal Styles */
        .confirmation-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 3000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .confirmation-modal.active {
            display: flex;
        }

        .confirmation-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 3001;
        }

        .confirmation-content {
            position: relative;
            background: white;
            border-radius: 16px;
            padding: 24px;
            max-width: 400px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            z-index: 3002;
            transform: scale(0.9);
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            text-align: center;
        }

        .confirmation-modal.active .confirmation-content {
            transform: scale(1);
            opacity: 1;
        }

        .confirmation-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 16px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fef2f2;
            animation: iconPop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .confirmation-icon.delete {
            background: #fef2f2;
        }

        .confirmation-icon.delete svg {
            color: #ef4444;
        }

        .confirmation-icon svg {
            width: 32px;
            height: 32px;
        }

        @keyframes iconPop {
            0% {
                transform: scale(0);
            }

            50% {
                transform: scale(1.1);
            }

            100% {
                transform: scale(1);
            }
        }

        .confirmation-title {
            font-size: 20px;
            font-weight: 600;
            color: #1a202c;
            margin: 0 0 8px 0;
        }

        .confirmation-message {
            font-size: 14px;
            color: #718096;
            margin: 0 0 24px 0;
            line-height: 1.5;
        }

        .confirmation-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .confirmation-btn {
            padding: 10px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            flex: 1;
            max-width: 140px;
        }

        .confirmation-btn-cancel {
            background: #f1f3f5;
            color: #4a5568;
        }

        .confirmation-btn-cancel:hover {
            background: #e9ecef;
            transform: translateY(-1px);
        }

        .confirmation-btn-confirm {
            background: #ef4444;
            color: white;
            box-shadow: 0 2px 8px rgba(239, 68, 68, 0.25);
        }

        .confirmation-btn-confirm:hover {
            background: #dc2626;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.35);
        }

        .confirmation-btn:active {
            transform: translateY(0);
        }

        @media (max-width: 768px) {
            .confirmation-modal {
                padding: 16px;
            }

            .confirmation-content {
                padding: 20px;
                max-width: 100%;
            }

            .confirmation-icon {
                width: 56px;
                height: 56px;
                margin-bottom: 14px;
            }

            .confirmation-icon svg {
                width: 28px;
                height: 28px;
            }

            .confirmation-title {
                font-size: 18px;
            }

            .confirmation-message {
                font-size: 13px;
                margin-bottom: 20px;
            }

            .confirmation-actions {
                flex-direction: column;
                gap: 10px;
            }

            .confirmation-btn {
                max-width: none;
                width: 100%;
                padding: 12px 24px;
            }
        }

        /* Location Error Modal Styles */
        .location-error-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 3000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .location-error-modal.active {
            display: flex;
        }

        .location-error-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            z-index: 3001;
        }

        .location-error-content {
            position: relative;
            background: white;
            border-radius: 16px;
            padding: 32px 24px 24px;
            max-width: 420px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            z-index: 3002;
            transform: scale(0.9);
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            text-align: center;
        }

        .location-error-modal.active .location-error-content {
            transform: scale(1);
            opacity: 1;
        }

        .location-error-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: iconPop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .location-error-icon svg {
            width: 64px;
            height: 64px;
        }

        .location-error-title {
            font-family: 'Montserrat', sans-serif;
            font-size: 22px;
            font-weight: 700;
            color: #1e293b;
            margin: 0 0 12px 0;
        }

        .location-error-message {
            font-family: 'Montserrat', sans-serif;
            font-size: 15px;
            color: #64748b;
            line-height: 1.6;
            margin: 0 0 24px 0;
        }

        .location-error-actions {
            display: flex;
            gap: 12px;
            justify-content: center;
        }

        .location-error-btn {
            padding: 12px 32px;
            background: linear-gradient(135deg, #FFA53B 0%, #ff9500 100%);
            border: none;
            border-radius: 10px;
            color: white;
            font-family: 'Montserrat', sans-serif;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            min-width: 120px;
            box-shadow: 0 2px 8px rgba(255, 165, 59, 0.25);
        }

        .location-error-btn:hover {
            background: linear-gradient(135deg, #ff9500 0%, #ff8800 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(255, 165, 59, 0.35);
        }

        .location-error-btn:active {
            transform: translateY(0);
        }

        @media (max-width: 768px) {
            .location-error-modal {
                padding: 16px;
            }

            .location-error-content {
                padding: 28px 20px 20px;
                max-width: 100%;
            }

            .location-error-icon {
                width: 56px;
                height: 56px;
                margin-bottom: 16px;
            }

            .location-error-icon svg {
                width: 56px;
                height: 56px;
            }

            .location-error-title {
                font-size: 20px;
            }

            .location-error-message {
                font-size: 14px;
                margin-bottom: 20px;
            }

            .location-error-btn {
                width: 100%;
                padding: 12px 24px;
            }
        }

        /* Success/Error Notification Styles */
        .profile-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: white;
            padding: 16px 20px;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: 12px;
            z-index: 10001;
            transform: translateX(400px);
            opacity: 0;
            transition: transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.5s ease;
            max-width: 400px;
            font-size: 14px;
            font-weight: 500;
            pointer-events: none;
        }

        .profile-notification.show {
            transform: translateX(0);
            opacity: 1;
            pointer-events: auto;
        }

        .profile-notification.hiding {
            transform: translateX(400px);
            opacity: 0;
            transition: transform 0.4s cubic-bezier(0.55, 0.055, 0.675, 0.19), opacity 0.4s ease;
        }

        .profile-notification.success {
            border-left: 4px solid #10b981;
            color: #065f46;
            background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%);
        }

        .profile-notification.success svg {
            color: #10b981;
            flex-shrink: 0;
            width: 20px;
            height: 20px;
            animation: checkmarkPop 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.2s both;
        }

        @keyframes checkmarkPop {
            0% {
                transform: scale(0);
                opacity: 0;
            }

            50% {
                transform: scale(1.2);
            }

            100% {
                transform: scale(1);
                opacity: 1;
            }
        }

        .profile-notification.error {
            border-left: 4px solid #ef4444;
            color: #991b1b;
            background: linear-gradient(135deg, #fef2f2 0%, #ffffff 100%);
        }

        .profile-notification.error svg {
            color: #ef4444;
            flex-shrink: 0;
            width: 20px;
            height: 20px;
            animation: errorShake 0.5s ease-in-out 0.2s both;
        }

        @keyframes errorShake {

            0%,
            100% {
                transform: translateX(0);
            }

            25% {
                transform: translateX(-5px);
            }

            75% {
                transform: translateX(5px);
            }
        }

        .profile-notification.info {
            border-left: 4px solid #3b82f6;
            color: #1e40af;
            background: linear-gradient(135deg, #eff6ff 0%, #ffffff 100%);
        }

        .profile-notification.info svg {
            color: #3b82f6;
            flex-shrink: 0;
            width: 20px;
            height: 20px;
        }

        @keyframes spin {
            from {
                transform: rotate(0deg);
            }

            to {
                transform: rotate(360deg);
            }
        }

        @media (max-width: 768px) {
            .profile-notification {
                bottom: 20px;
                right: 10px;
                left: 10px;
                max-width: none;
                transform: translateY(100px);
                padding: 14px 18px;
                font-size: 13px;
                transition: transform 0.5s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.5s ease;
            }

            .profile-notification.show {
                transform: translateY(0);
            }

            .profile-notification.hiding {
                transform: translateY(100px);
                transition: transform 0.4s cubic-bezier(0.55, 0.055, 0.675, 0.19), opacity 0.4s ease;
            }

            .profile-notification.success svg,
            .profile-notification.error svg {
                width: 18px;
                height: 18px;
            }
        }

        /* Mobile responsive refinements for profile form */
        @media (max-width: 640px) {
            .profile-container {
                padding: 0;
                margin: 0;
                border-radius: 0;
                box-shadow: none;
            }

            body {
                padding: 0;
            }

            .profile-header {
                padding: 30px 20px;
            }

            .profile-avatar {
                width: 70px;
                height: 70px;
            }

            .profile-name {
                font-size: 20px;
            }

            .profile-content {
                padding: 20px 16px;
            }

            .info-grid {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .info-item {
                padding: 12px 15px;
            }

            .form-input,
            select.form-input,
            .phone-input {
                font-size: 14px !important;
                padding: 8px 12px !important;
                height: 40px !important;
            }

            .btn {
                padding: 10px 15px;
                font-size: 14px;
            }

            .edit-modal-content {
                padding: 15px;
                max-width: 100%;
                border-radius: 0;
                height: 100%;
            }

            .edit-modal {
                padding: 0;
            }
        }
    </style>

    <script>
        const basePath = '<?php echo $basePath; ?>';
        const csrfToken = '<?php echo generateCSRFToken(); ?>';
        const userEmail = '<?php echo htmlspecialchars($user['email']); ?>';
        const userId = <?php echo $user['id']; ?>;
        // Confirmation Modal Handler
        (function () {
            const confirmationModal = document.getElementById('confirmationModal');
            const confirmationBackdrop = confirmationModal ? confirmationModal.querySelector('.confirmation-backdrop') : null;
            const confirmationTitle = document.getElementById('confirmationTitle');
            const confirmationMessage = document.getElementById('confirmationMessage');
            const confirmationIcon = document.getElementById('confirmationIcon');
            const confirmationCancel = document.getElementById('confirmationCancel');
            const confirmationConfirm = document.getElementById('confirmationConfirm');

            let confirmationCallback = null;

            window.showConfirmationModal = function (title, message, type, onConfirm) {
                if (!confirmationModal) return;

                confirmationCallback = onConfirm;

                // Set content
                if (confirmationTitle) confirmationTitle.textContent = title;
                if (confirmationMessage) confirmationMessage.textContent = message;

                // Set icon based on type
                if (confirmationIcon) {
                    if (type === 'delete') {
                        confirmationIcon.className = 'confirmation-icon delete';
                        confirmationIcon.innerHTML = `
                            <svg viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 1v10a2 2 0 002 2h8a2 2 0 002-2V5a1 1 0 100-1h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/>
                            </svg>
                        `;
                    } else {
                        confirmationIcon.className = 'confirmation-icon';
                        confirmationIcon.innerHTML = `
                            <svg viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                            </svg>
                        `;
                    }
                }

                // Show modal
                confirmationModal.classList.add('active');
                document.body.style.overflow = 'hidden';
            };

            function closeConfirmationModal() {
                if (!confirmationModal) return;
                confirmationModal.classList.remove('active');
                document.body.style.overflow = '';
                confirmationCallback = null;
            }

            function handleConfirm() {
                if (confirmationCallback) {
                    confirmationCallback();
                }
                closeConfirmationModal();
            }

            if (confirmationCancel) {
                confirmationCancel.addEventListener('click', closeConfirmationModal);
            }

            if (confirmationConfirm) {
                confirmationConfirm.addEventListener('click', handleConfirm);
            }

            if (confirmationBackdrop) {
                confirmationBackdrop.addEventListener('click', closeConfirmationModal);
            }

            // Close on Escape key
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && confirmationModal && confirmationModal.classList.contains('active')) {
                    closeConfirmationModal();
                }
            });
        })();

        // Profile Picture Upload and Remove (in Edit Modal only)
        // Make profilePictureChanged accessible globally for change detection
        window.profilePictureChanged = false;
        window.profilePictureDeleted = false;

        (function () {
            let editFileInput = document.getElementById('editProfilePictureInput');
            const removeBtn = document.getElementById('removePictureBtn');
            const previewContainer = document.getElementById('currentPicturePreview');
            const previewImg = document.getElementById('previewImg');
            const avatarImg = document.getElementById('profileAvatarImg');
            const avatarInitials = document.getElementById('profileAvatarInitials');
            const changeBtn = document.querySelector('.change-picture-btn');
            const changeBtnContainer = document.querySelector('.profile-picture-edit');

            const triggerFilePicker = () => {
                let fi = document.getElementById('editProfilePictureInput');
                if (fi) {
                    fi.value = ''; // reset so same file can be reselected
                    fi.click();
                }
            };

            // Function to initialize file input handler (call when modal opens)
            function initializeFileInput() {
                // Re-get the file input in case it wasn't found initially
                if (!editFileInput) {
                    editFileInput = document.getElementById('editProfilePictureInput');
                }

                if (!editFileInput) {
                    console.error('File input not found');
                    return;
                }

                // Remove existing listener if any (to prevent duplicates)
                const newInput = editFileInput.cloneNode(true);
                editFileInput.parentNode.replaceChild(newInput, editFileInput);
                editFileInput = newInput;

                // Handle profile picture upload
                editFileInput.addEventListener('change', function (e) {
                    const file = e.target.files[0];
                    if (!file) return;

                    // Validate file type
                    if (!file.type.match('image.*')) {
                        showErrorMessage('Please select an image file.');
                        return;
                    }

                    // Validate file size (5MB)
                    if (file.size > 5 * 1024 * 1024) {
                        showErrorMessage('File size must be less than 5MB.');
                        return;
                    }

                    // Show preview
                    const reader = new FileReader();
                    reader.onload = function (e) {
                        if (previewContainer) {
                            // Remove placeholder if exists
                            const placeholder = previewContainer.querySelector('.no-picture-placeholder');
                            if (placeholder) placeholder.remove();

                            // Create or update image
                            let img = previewContainer.querySelector('img');
                            if (!img) {
                                img = document.createElement('img');
                                img.id = 'previewImg';
                                img.alt = 'Preview';
                                previewContainer.insertBefore(img, previewContainer.firstChild);
                            }
                            img.src = e.target.result;
                            img.style.display = 'block';

                            // Show remove button if not exists
                            if (!previewContainer.querySelector('.remove-picture-btn')) {
                                const removeBtn = document.createElement('button');
                                removeBtn.type = 'button';
                                removeBtn.className = 'remove-picture-btn';
                                removeBtn.id = 'removePictureBtn';
                                removeBtn.innerHTML = `
                                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <path d="M3 6h14M8 6V4a2 2 0 012-2h0a2 2 0 012 2v2m3 0v10a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14zM10 11v6M14 11v6"/>
                                    </svg>
                                `;
                                removeBtn.title = 'Delete profile picture';
                                previewContainer.appendChild(removeBtn);

                                // Add event listener to new remove button
                                removeBtn.addEventListener('click', handleRemovePicture);
                            }

                            // Update change button text
                            if (changeBtn) {
                                const btnSpan = changeBtn.querySelector('span');
                                if (btnSpan) {
                                    btnSpan.textContent = 'Change';
                                } else {
                                    // Fallback: find text node
                                    const btnText = Array.from(changeBtn.childNodes).find(node => node.nodeType === 3);
                                    if (btnText) {
                                        btnText.textContent = 'Change';
                                    }
                                }
                            }
                        }
                    };
                    reader.readAsDataURL(file);

                    // Upload file
                    const formData = new FormData();
                    formData.append('profile_picture', file);
                    formData.append('csrf_token', csrfToken);

                    // Show loading
                    if (changeBtn) {
                        const originalText = changeBtn.innerHTML;
                        changeBtn.innerHTML = '<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" style="animation: spin 1s linear infinite;"><path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg> Uploading...';
                        changeBtn.style.pointerEvents = 'none';
                    }

                    fetch(basePath + '/app/handlers/upload_profile_picture.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json())
                        .then(result => {
                            if (result.success) {
                                // Mark profile picture as changed
                                window.profilePictureChanged = true;
                                window.profilePictureDeleted = false;

                                showSuccessMessage('Profile picture uploaded successfully!');

                                // Update main profile avatar
                                if (avatarImg) {
                                    avatarImg.src = result.picture_url + '?t=' + Date.now();
                                    avatarImg.style.display = 'block';
                                    if (avatarInitials) avatarInitials.style.display = 'none';
                                }

                                // Update preview image source
                                if (previewImg) {
                                    previewImg.src = result.picture_url + '?t=' + Date.now();
                                }
                            } else {
                                showErrorMessage(result.error || 'Failed to upload profile picture');
                                // Revert preview
                                if (previewContainer) {
                                    const img = previewContainer.querySelector('img');
                                    if (img && img.src.includes('data:')) {
                                        img.remove();
                                        const placeholder = document.createElement('div');
                                        placeholder.className = 'no-picture-placeholder';
                                        placeholder.innerHTML = `
                                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5">
                                            <path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                        </svg>
                                        <span>No picture</span>
                                    `;
                                        previewContainer.appendChild(placeholder);
                                    }
                                }
                            }
                        })
                        .catch(error => {
                            console.error('Upload error:', error);
                            showErrorMessage('Failed to upload profile picture. Please try again.');
                        })
                        .finally(() => {
                            // Restore button
                            if (changeBtn) {
                                changeBtn.innerHTML = `
                                <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                                <span>Change</span>
                            `;
                                changeBtn.style.pointerEvents = 'auto';
                            }
                        });
                });
            }

            // Initialize file input handler immediately and when modal opens
            initializeFileInput();

            // Also ensure click triggers file picker (backup)
            if (changeBtn) {
                changeBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    triggerFilePicker();
                });
            }
            if (changeBtnContainer) {
                changeBtnContainer.addEventListener('click', function (e) {
                    if (e.target.closest('.change-picture-btn')) return;
                    if (e.target.closest('.remove-picture-btn')) return;
                    // Allow clicking on the image area to open picker
                    triggerFilePicker();
                });
            }

            // Expose function to be called when modal opens
            window.initializeProfilePictureUpload = initializeFileInput;

            // Handle remove profile picture
            function handleRemovePicture() {
                showConfirmationModal(
                    'Delete Profile Picture',
                    'Are you sure you want to remove your profile picture? This action cannot be undone.',
                    'delete',
                    function () {
                        // User confirmed - proceed with deletion
                        proceedWithPictureDeletion();
                    }
                );
            }

            function proceedWithPictureDeletion() {
                // Always get fresh references to avoid stale elements
                const btn = document.getElementById('removePictureBtn');
                const previewContainer = document.getElementById('currentPicturePreview');
                const changeBtn = document.querySelector('.change-picture-btn');
                const avatarImg = document.getElementById('profileAvatarImg');
                const avatarInitials = document.getElementById('profileAvatarInitials');

                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="animation: spin 1s linear infinite;"><path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48l2.83-2.83" stroke-linecap="round"/></svg>';
                    btn.title = 'Removing...';
                }

                fetch(basePath + '/app/handlers/remove_profile_picture.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf_token: csrfToken })
                })
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.json();
                })
                .then(result => {
                    if (result.success) {
                        window.profilePictureDeleted = true;
                        window.profilePictureChanged = false;
                        
                        showSuccessMessage('Profile picture removed successfully!');

                        // Clear preview area
                        if (previewContainer) {
                            previewContainer.innerHTML = `
                                <div class="no-picture-placeholder">
                                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                    <span>No picture</span>
                                </div>
                            `;
                        }

                        // Update page avatars
                        if (avatarImg) avatarImg.style.display = 'none';
                        if (avatarInitials) avatarInitials.style.display = 'flex';

                        // Update change button text to "Add"
                        if (changeBtn) {
                            const btnSpan = changeBtn.querySelector('span');
                            if (btnSpan) {
                                btnSpan.textContent = 'Add';
                            } else {
                                changeBtn.innerHTML = `
                                    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5">
                                        <path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                    <span>Add</span>
                                `;
                            }
                        }
                    } else {
                        throw new Error(result.error || 'Failed to remove profile picture');
                    }
                })
                .catch(error => {
                    console.error('Remove error:', error);
                    showErrorMessage(error.message || 'Failed to remove profile picture. Please try again.');
                    
                    // Revert button state if failed
                    const currentBtn = document.getElementById('removePictureBtn');
                    if (currentBtn) {
                        currentBtn.disabled = false;
                        currentBtn.innerHTML = `
                            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path d="M3 6h14M8 6V4a2 2 0 012-2h0a2 2 0 012 2v2m3 0v10a2 2 0 01-2 2H7a2 2 0 01-2-2V6h14zM10 11v6M14 11v6"/>
                            </svg>
                        `;
                        currentBtn.title = 'Delete profile picture';
                    }
                });
            }

            // Expose for event listeners
            window.handleRemovePicture = handleRemovePicture;
            
            // Add click listener initially
            const initialRemoveBtn = document.getElementById('removePictureBtn');
            if (initialRemoveBtn) {
                initialRemoveBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    handleRemovePicture();
                });
            }
        })();


        // Edit Modal
        (function () {
            const editBtn = document.getElementById('editProfileBtn');
            const editModal = document.getElementById('editModal');
            const closeBtn = document.getElementById('closeEditModal');
            const backdrop = document.getElementById('editModalBackdrop');
            const cancelBtn = document.getElementById('cancelEditBtn');
            const editForm = document.getElementById('editProfileForm');
            const useCurrentStreetBtn = document.getElementById('useCurrentLocationStreet');

            // Store original values when modal opens
            let originalValues = {};
            let profilePictureChanged = false;
            let profilePictureDeleted = false;

            function openModal() {
                // Prevent body scroll
                document.body.style.overflow = 'hidden';
                document.body.style.position = 'fixed';
                document.body.style.width = '100%';
                document.body.style.height = '100%';

                editModal.classList.add('active');

                // Initialize file input handler when modal opens
                if (window.initializeProfilePictureUpload) {
                    window.initializeProfilePictureUpload();
                }

                // Reset profile picture change tracking
                profilePictureChanged = false;
                profilePictureDeleted = false;
                if (window.profilePictureChanged !== undefined) window.profilePictureChanged = false;
                if (window.profilePictureDeleted !== undefined) window.profilePictureDeleted = false;

                // Store original values for comparison
                const previewContainer = document.getElementById('currentPicturePreview');
                const hasPicture = previewContainer && previewContainer.querySelector('img') && !previewContainer.querySelector('.no-picture-placeholder');

                // Get phone value without +977 for comparison
                const phoneInputForCompare = document.getElementById('editPhone');
                const phoneForCompare = phoneInputForCompare ? phoneInputForCompare.value.replace(/[^0-9]/g, '') : '';

                originalValues = {
                    name: document.getElementById('editName').value.trim(),
                    phone: phoneForCompare,
                    delivery_location: document.getElementById('editDeliveryLocation').value.trim(),
                    street_location: document.getElementById('editStreetLocation').value.trim(),
                    location_lat: document.getElementById('editLocationLat').value.trim(),
                    location_lng: document.getElementById('editLocationLng').value.trim(),
                    has_profile_picture: hasPicture
                };

                // Initialize delivery location visibility
                if (typeof toggleManualLocation === 'function') {
                    toggleManualLocation('editDeliveryLocation', 'editManualLocationContainer');
                }


                // Scroll modal container to top to prevent cropping
                setTimeout(() => {
                    editModal.scrollTop = 0;
                    const modalContent = editModal.querySelector('.edit-modal-content');
                    if (modalContent) {
                        modalContent.scrollTop = 0;
                    }
                }, 10);
            }

            function closeModal() {
                editModal.classList.remove('active');
                // Restore body scroll
                document.body.style.overflow = '';
                document.body.style.position = '';
                document.body.style.width = '';
                document.body.style.height = '';
            }

            if (editBtn) editBtn.addEventListener('click', openModal);



            if (closeBtn) closeBtn.addEventListener('click', closeModal);
            if (backdrop) backdrop.addEventListener('click', closeModal);
            if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

            // Phone number validation - only allow digits
            (function () {
                const phoneInput = document.getElementById('editPhone');
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
            }
            )();


            // Form submission
            if (editForm) {
                editForm.addEventListener('submit', function (e) {
                    e.preventDefault();

                    const submitBtn = editForm.querySelector('button[type="submit"]');
                    const originalBtnText = submitBtn ? submitBtn.innerHTML : '';

                    // Get current form values
                    const phoneInputForCurrent = document.getElementById('editPhone');
                    const phoneForCurrent = phoneInputForCurrent ? phoneInputForCurrent.value.replace(/[^0-9]/g, '') : '';

                    const deliverySelect = document.getElementById('editDeliveryLocation');
                    const manualInput = document.getElementById('editManualLocation');
                    const finalDeliveryLoc = (deliverySelect.value === 'Other') ? manualInput.value.trim() : deliverySelect.value;

                    const currentValues = {
                        name: document.getElementById('editName').value.trim(),
                        phone: phoneForCurrent,
                        delivery_location: finalDeliveryLoc,
                        street_location: document.getElementById('editStreetLocation').value.trim(),
                        location_lat: document.getElementById('editLocationLat').value.trim(),
                        location_lng: document.getElementById('editLocationLng').value.trim()
                    };

                    // Check current profile picture state
                    const previewContainer = document.getElementById('currentPicturePreview');
                    const currentHasPicture = previewContainer && previewContainer.querySelector('img') && !previewContainer.querySelector('.no-picture-placeholder');

                    // Check if any values have changed (including profile picture)
                    const picChanged = window.profilePictureChanged || profilePictureChanged;
                    const picDeleted = window.profilePictureDeleted || profilePictureDeleted;

                    const hasChanges =
                        currentValues.name !== originalValues.name ||
                        currentValues.phone !== originalValues.phone ||
                        currentValues.delivery_location !== originalValues.delivery_location ||
                        currentValues.street_location !== originalValues.street_location ||
                        currentValues.location_lat !== originalValues.location_lat ||
                        currentValues.location_lng !== originalValues.location_lng ||
                        picChanged ||
                        picDeleted ||
                        currentHasPicture !== originalValues.has_profile_picture;

                    // If no changes, show message and return
                    if (!hasChanges) {
                        showInfoMessage('No changes detected. Please make changes before saving.');
                        if (submitBtn) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = originalBtnText;
                        }
                        return;
                    }

                    // Disable submit button and show loading
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" style="animation: spin 1s linear infinite;"><path d="M10 3v3m0 8v3m7-7h-3M6 10H3m13.364-5.364l-2.121 2.121M6.757 13.243l-2.121 2.121m10.728 0l-2.121-2.121M6.757 6.757L4.636 4.636"/></svg> Saving...';
                    }

                    // Format phone number with +977 prefix
                    const phoneInput = document.getElementById('editPhone');
                    let phoneValue = phoneInput.value.replace(/[^0-9]/g, '');
                    if (phoneValue.length == 10) {
                        phoneValue = '+977' + phoneValue;
                    } else if (phoneValue.length > 0) {
                        // If phone has digits but wrong length, keep as is (will be validated server-side)
                        phoneValue = phoneInput.value.trim();
                    } else {
                        phoneValue = '';
                    }

                    const formData = {
                        csrf_token: csrfToken,
                        name: currentValues.name,
                        phone: phoneValue,
                        delivery_location: currentValues.delivery_location,
                        street_location: currentValues.street_location,
                        location_lat: currentValues.location_lat,
                        location_lng: currentValues.location_lng
                    };

                    const endpoint = basePath + '/app/handlers/update_profile.php';

                    // helper: update UI after successful response
                    function handleSuccess(updatedData) {
                        if (updatedData.name) {
                            const nameElement = document.querySelector('.profile-name');
                            if (nameElement) nameElement.textContent = updatedData.name;
                        }

                        const infoItems = document.querySelectorAll('.info-item');
                        infoItems.forEach(item => {
                            const label = item.querySelector('.info-label');
                            if (!label) return;
                            const labelText = label.textContent.trim();
                            const valueDiv = item.querySelector('.info-value');
                            if (!valueDiv) return;

                            if (labelText === 'Phone Number') {
                                valueDiv.textContent = updatedData.phone || 'Not provided';
                                valueDiv.className = updatedData.phone ? 'info-value' : 'info-value empty';
                            } else if (labelText === 'Delivery Location') {
                                valueDiv.textContent = updatedData.delivery_location || 'Not provided';
                                valueDiv.className = updatedData.delivery_location ? 'info-value' : 'info-value empty';
                            } else if (labelText === 'Street Location') {
                                valueDiv.textContent = updatedData.street_location || 'Not provided';
                                valueDiv.className = updatedData.street_location ? 'info-value' : 'info-value empty';
                            }
                        });



                        const previewContainerAfter = document.getElementById('currentPicturePreview');
                        const hasPictureAfter = previewContainerAfter && previewContainerAfter.querySelector('img') && !previewContainerAfter.querySelector('.no-picture-placeholder');

                        originalValues = {
                            name: updatedData.name || currentValues.name,
                            phone: updatedData.phone || currentValues.phone,
                            delivery_location: updatedData.delivery_location || currentValues.delivery_location,
                            street_location: updatedData.street_location || currentValues.street_location,
                            location_lat: updatedData.location_lat || currentValues.location_lat,
                            location_lng: updatedData.location_lng || currentValues.location_lng,
                            has_profile_picture: hasPictureAfter
                        };

                        profilePictureChanged = false;
                        profilePictureDeleted = false;
                        if (window.profilePictureChanged !== undefined) window.profilePictureChanged = false;
                        if (window.profilePictureDeleted !== undefined) window.profilePictureDeleted = false;

                        showSuccessMessage('Profile updated successfully!');
                        setTimeout(() => closeModal(), 1500);
                        setTimeout(() => {
                            window.location.href = window.location.pathname + '?t=' + Date.now();
                        }, 4000);
                    }

                    // helper: parse JSON safely
                    function parseJsonSafe(text) {
                        try { return JSON.parse(text); } catch (e) { return null; }
                    }

                    // primary: JSON request
                    const jsonBody = JSON.stringify(formData);

                    function sendJson() {
                        return fetch(endpoint, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: jsonBody
                        }).then(async response => {
                            const text = await response.text();
                            const data = parseJsonSafe(text);
                            return { ok: response.ok, data, text, status: response.status };
                        });
                    }

                    // fallback: multipart FormData
                    function sendFormData() {
                        const fd = new FormData();
                        Object.entries(formData).forEach(([key, val]) => fd.append(key, val ?? ''));
                        return fetch(endpoint, {
                            method: 'POST',
                            body: fd
                        }).then(async response => {
                            const text = await response.text();
                            const data = parseJsonSafe(text);
                            return { ok: response.ok, data, text, status: response.status };
                        });
                    }

                    function handleResult(result) {
                        if (result?.data?.success) {
                            handleSuccess(result.data);
                            return true;
                        }
                        return false;
                    }

                    sendJson()
                        .then(jsonRes => {
                            if (handleResult(jsonRes)) return;
                            // If JSON failed or returned error, try FormData fallback
                            return sendFormData().then(formRes => {
                                if (handleResult(formRes)) return;
                                const errMsg = (formRes?.data && formRes.data.error) ? formRes.data.error
                                    : (jsonRes?.data && jsonRes.data.error) ? jsonRes.data.error
                                        : (formRes?.text ? `Failed to update profile: ${formRes.text.slice(0, 180)}` :
                                            jsonRes?.text ? `Failed to update profile: ${jsonRes.text.slice(0, 180)}` :
                                                'Failed to update profile. Please try again.');
                                console.error('Update profile error', { jsonRes, formRes });
                                showErrorMessage(errMsg);
                                if (submitBtn) {
                                    submitBtn.disabled = false;
                                    submitBtn.innerHTML = originalBtnText;
                                }
                            });
                        })
                        .catch(error => {
                            console.error('Update error:', error);
                            showErrorMessage('Failed to update profile. Please try again.');
                            if (submitBtn) {
                                submitBtn.disabled = false;
                                submitBtn.innerHTML = originalBtnText;
                            }
                        });
                });
            }

            // Success message function
            function showSuccessMessage(message) {
                // Remove any existing messages
                const existingMsg = document.querySelector('.profile-notification');
                if (existingMsg) {
                    existingMsg.classList.remove('show');
                    setTimeout(() => existingMsg.remove(), 300);
                }

                const notification = document.createElement('div');
                notification.className = 'profile-notification success';
                notification.innerHTML = `
                    <svg viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    <span>${message}</span>
                `;
                document.body.appendChild(notification);

                // Force reflow to ensure initial state is applied
                notification.offsetHeight;

                // Trigger animation
                setTimeout(() => {
                    notification.classList.add('show');
                }, 50);

                // Auto remove after 4 seconds (longer for better visibility)
                setTimeout(() => {
                    notification.classList.remove('show');
                    notification.classList.add('hiding');
                    setTimeout(() => {
                        if (notification.parentNode) {
                            notification.remove();
                        }
                    }, 400);
                }, 4000);
            }

            // Error message function
            function showErrorMessage(message) {
                // Remove any existing messages
                const existingMsg = document.querySelector('.profile-notification');
                if (existingMsg) {
                    existingMsg.classList.remove('show');
                    setTimeout(() => existingMsg.remove(), 300);
                }

                const notification = document.createElement('div');
                notification.className = 'profile-notification error';
                notification.innerHTML = `
                    <svg viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                    <span>${message}</span>
                `;
                document.body.appendChild(notification);

                // Force reflow to ensure initial state is applied
                notification.offsetHeight;

                // Trigger animation
                setTimeout(() => {
                    notification.classList.add('show');
                }, 50);

                // Auto remove after 5 seconds (longer for errors)
                setTimeout(() => {
                    notification.classList.remove('show');
                    notification.classList.add('hiding');
                    setTimeout(() => {
                        if (notification.parentNode) {
                            notification.remove();
                        }
                    }, 400);
                }, 5000);
            }

            // Info message function (for no changes detected)
            function showInfoMessage(message) {
                // Remove any existing messages
                const existingMsg = document.querySelector('.profile-notification');
                if (existingMsg) {
                    existingMsg.classList.remove('show');
                    setTimeout(() => existingMsg.remove(), 300);
                }

                const notification = document.createElement('div');
                notification.className = 'profile-notification info';
                notification.innerHTML = `
                    <svg viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                    </svg>
                    <span>${message}</span>
                `;
                document.body.appendChild(notification);

                // Force reflow to ensure initial state is applied
                notification.offsetHeight;

                // Trigger animation
                setTimeout(() => {
                    notification.classList.add('show');
                }, 50);

                // Auto remove after 3 seconds
                setTimeout(() => {
                    notification.classList.remove('show');
                    notification.classList.add('hiding');
                    setTimeout(() => {
                        if (notification.parentNode) {
                            notification.remove();
                        }
                    }, 400);
                }, 3000);
            }

            // Close on Escape key
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && editModal.classList.contains('active')) {
                    closeModal();
                }
            });
        })();

        // OTP Verification Modal for Profile Page
        (function () {
            function initOtpModal() {
                // Try multiple times to find the button (in case DOM isn't ready)
                let verifyEmailBtn = document.getElementById('verifyEmailBtn');
                if (!verifyEmailBtn) {
                    // Try again after a short delay
                    setTimeout(() => {
                        verifyEmailBtn = document.getElementById('verifyEmailBtn');
                        if (verifyEmailBtn) {
                            setupOtpModal(verifyEmailBtn);
                        } else {
                            console.warn('Verify Email button not found after retry');
                        }
                    }, 200);
                    return;
                }
                setupOtpModal(verifyEmailBtn);
            }

            function setupOtpModal(verifyEmailBtn) {
                // Ensure button is enabled and clickable
                verifyEmailBtn.disabled = false;
                verifyEmailBtn.style.pointerEvents = 'auto';
                verifyEmailBtn.style.cursor = 'pointer';
                verifyEmailBtn.style.userSelect = 'none';
                verifyEmailBtn.style.webkitUserSelect = 'none';

                const otpModal = document.getElementById('otpModalProfile');
                const otpInput = document.getElementById('otpModalInput');
                const otpVerifyBtn = document.getElementById('otpModalVerifyBtn');
                const otpCancelBtn = document.getElementById('otpModalCancelBtn');
                const otpResendLink = document.getElementById('otpModalResendLink');
                const otpError = document.getElementById('otpModalError');
                const otpSuccess = document.getElementById('otpModalSuccess');

                if (!otpModal) {
                    console.error('OTP modal not found');
                    return;
                }

                // Function to send OTP
                function sendOTP() {
                    const formData = new FormData();
                    formData.append('email', userEmail);
                    formData.append('csrf_token', csrfToken);

                    return fetch(basePath + '/auth/resend_otp.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json());
                }

                // Function to verify OTP
                function verifyOTP(otp) {
                    const formData = new FormData();
                    formData.append('otp', otp);
                    formData.append('email', userEmail);
                    formData.append('csrf_token', csrfToken);

                    return fetch(basePath + '/auth/verify_otp.php', {
                        method: 'POST',
                        body: formData
                    })
                        .then(response => response.json());
                }

                // Function to show error in modal
                function showOtpError(message) {
                    otpError.textContent = message;
                    otpError.style.display = 'block';
                    otpSuccess.style.display = 'none';
                    setTimeout(() => {
                        otpError.style.display = 'none';
                    }, 5000);
                }

                // Function to show success in modal
                function showOtpSuccess(message) {
                    otpSuccess.textContent = message;
                    otpSuccess.style.display = 'block';
                    otpError.style.display = 'none';
                }

                // Handle Verify Email button click - open modal and send OTP
                verifyEmailBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();

                    // Disable button and show loading
                    verifyEmailBtn.disabled = true;
                    const originalHTML = verifyEmailBtn.innerHTML;
                    verifyEmailBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor" style="animation: spin 1s linear infinite;"><path d="M10 3v3m0 8v3m7-7h-3M6 10H3m13.364-5.364l-2.121 2.121M6.757 13.243l-2.121 2.121m10.728 0l-2.121-2.121M6.757 6.757L4.636 4.636"/></svg> Sending OTP...';

                    // Send OTP and open modal
                    sendOTP()
                        .then(data => {
                            if (data.success) {
                                // Open modal and clear input
                                otpModal.classList.add('active');
                                otpInput.value = '';
                                otpInput.focus();
                                showOtpError('');
                                otpError.style.display = 'none';
                            } else {
                                showOtpError(data.error || 'Failed to send verification code. Please try again.');
                                verifyEmailBtn.disabled = false;
                                verifyEmailBtn.innerHTML = originalHTML;
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            showOtpError('Network error. Please check your connection and try again.');
                            verifyEmailBtn.disabled = false;
                            verifyEmailBtn.innerHTML = originalHTML;
                        });
                }, true);

                // Handle Resend link click
                if (otpResendLink) {
                    otpResendLink.addEventListener('click', function (e) {
                        e.preventDefault();

                        // Disable link temporarily
                        otpResendLink.style.pointerEvents = 'none';
                        otpResendLink.style.opacity = '0.6';
                        const originalText = otpResendLink.textContent;
                        otpResendLink.textContent = 'Sending...';

                        // Send OTP
                        sendOTP()
                            .then(data => {
                                if (data.success) {
                                    showOtpSuccess('New verification code sent! Please check your email.');
                                    otpInput.value = '';
                                    otpInput.focus();
                                    setTimeout(() => {
                                        otpSuccess.style.display = 'none';
                                    }, 3000);
                                } else {
                                    showOtpError(data.error || 'Failed to send verification code. Please try again.');
                                }
                                otpResendLink.style.pointerEvents = 'auto';
                                otpResendLink.style.opacity = '1';
                                otpResendLink.textContent = originalText;
                            })
                            .catch(error => {
                                console.error('Error:', error);
                                showOtpError('Network error. Please check your connection and try again.');
                                otpResendLink.style.pointerEvents = 'auto';
                                otpResendLink.style.opacity = '1';
                                otpResendLink.textContent = originalText;
                            });
                    });
                }

                // Function to handle OTP verification
                function handleVerifyOTP() {
                    const otp = otpInput.value.trim();
                    if (!otp || otp.length !== 6) {
                        showOtpError('Please enter a valid 6-digit code');
                        return;
                    }

                    // Disable button and input
                    otpVerifyBtn.disabled = true;
                    otpInput.disabled = true;
                    const originalHTML = otpVerifyBtn.innerHTML;
                    otpVerifyBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor" style="animation: spin 1s linear infinite;"><path d="M10 3v3m0 8v3m7-7h-3M6 10H3m13.364-5.364l-2.121 2.121M6.757 13.243l-2.121 2.121m10.728 0l-2.121-2.121M6.757 6.757L4.636 4.636"/></svg> Verifying...';

                    // Verify OTP
                    verifyOTP(otp)
                        .then(data => {
                            if (data.success) {
                                showOtpSuccess('Email verified successfully!');
                                setTimeout(() => {
                                    // Reload page to update verification status
                                    window.location.reload();
                                }, 1500);
                            } else {
                                showOtpError(data.error || 'Invalid verification code. Please try again.');
                                otpInput.value = '';
                                otpInput.focus();
                                otpInput.disabled = false;
                                otpVerifyBtn.disabled = false;
                                otpVerifyBtn.innerHTML = originalHTML;
                            }
                        })
                        .catch(error => {
                            console.error('Error:', error);
                            showOtpError('Network error. Please check your connection and try again.');
                            otpInput.disabled = false;
                            otpVerifyBtn.disabled = false;
                            otpVerifyBtn.innerHTML = originalHTML;
                        });
                }

                // Handle Verify button click
                if (otpVerifyBtn) {
                    otpVerifyBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        handleVerifyOTP();
                    });
                }

                // Handle OTP input - only allow numbers
                if (otpInput) {
                    otpInput.addEventListener('input', function (e) {
                        // Only allow numbers
                        this.value = this.value.replace(/[^0-9]/g, '');
                        // Limit to 6 digits
                        if (this.value.length > 6) {
                            this.value = this.value.slice(0, 6);
                        }
                        // Clear error when user types
                        if (otpError.style.display === 'block') {
                            otpError.style.display = 'none';
                        }
                    });

                    // Handle Enter key
                    otpInput.addEventListener('keypress', function (e) {
                        if (e.key === 'Enter' && this.value.length === 6) {
                            e.preventDefault();
                            handleVerifyOTP();
                        }
                    });

                    // Handle paste - only allow numbers
                    otpInput.addEventListener('paste', function (e) {
                        e.preventDefault();
                        const paste = (e.clipboardData || window.clipboardData).getData('text');
                        const numbers = paste.replace(/[^0-9]/g, '').slice(0, 6);
                        this.value = numbers;
                        if (numbers.length === 6) {
                            handleVerifyOTP();
                        }
                    });
                }

                // Handle Cancel button click
                if (otpCancelBtn) {
                    otpCancelBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        otpModal.classList.remove('active');
                        otpInput.value = '';
                        otpError.style.display = 'none';
                        otpSuccess.style.display = 'none';
                    });
                }

                // Close modal when clicking backdrop
                const backdrop = otpModal.querySelector('.otp-modal-profile-backdrop');
                if (backdrop) {
                    backdrop.addEventListener('click', function () {
                        otpModal.classList.remove('active');
                        otpInput.value = '';
                        otpError.style.display = 'none';
                        otpSuccess.style.display = 'none';
                    });
                }

                // Also handle touch events for mobile
                verifyEmailBtn.addEventListener('touchend', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    verifyEmailBtn.click();
                }, { passive: false });
            }

            // Wait for DOM to be ready and initialize
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initOtpModal);
            } else {
                // DOM already loaded, run immediately
                setTimeout(initOtpModal, 100);
            }
        })();
    </script>

    <!-- Mobile Navigation Script -->
    <script src="<?php echo $basePath; ?>/assets/js/dv_mobile_nav.js"></script>

    <script>
        // Check for edit query parameter to auto-open edit modal
        document.addEventListener('DOMContentLoaded', function () {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('edit') === 'true') {
                const editProfileBtn = document.getElementById('editProfileBtn');
                if (editProfileBtn) {
                    // Small delay to ensure smooth transition and modal logic is ready
                    setTimeout(() => {
                        editProfileBtn.click();
                        // Optional: Clean up URL
                        window.history.replaceState({}, document.title, window.location.pathname);
                    }, 300);
                }
            }
        });
        // Geolocation Script
        // Toggle manual location input
        function toggleManualLocation(selectId, containerId) {
            const select = document.getElementById(selectId);
            const container = document.getElementById(containerId);
            if (select && container) {
                container.style.display = (select.value === 'Other') ? 'block' : 'none';
                if (select.value === 'Other') {
                    const manualInput = container.querySelector('input');
                    if (manualInput) manualInput.focus();
                }
            }
        }

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
                    this.textContent = "Locating...";
                    this.disabled = true;

                    navigator.geolocation.getCurrentPosition(
                        async function (position) {
                            btn.textContent = originalText;
                            btn.disabled = false;

                            const lat = position.coords.latitude;
                            const lng = position.coords.longitude;

                            // Field mapping
                            const latInput = document.querySelector('#location_lat, #editLocationLat, #delivery_lat');
                            const lngInput = document.querySelector('#location_lng, #editLocationLng, #delivery_lng');
                            const streetInput = document.querySelector('#street_location, #editStreetLocation');
                            const deliverySelect = document.getElementById('editDeliveryLocation');
                            const manualContainer = document.getElementById('editManualLocationContainer');
                            const manualInput = document.getElementById('editManualLocation');

                            if (latInput) latInput.value = lat;
                            if (lngInput) lngInput.value = lng;

                            // Show coordinates in Street Location as requested (589)
                            if (streetInput) {
                                streetInput.value = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
                                streetInput.dispatchEvent(new Event('input'));
                            }

                            btn.textContent = originalText;
                            btn.disabled = false;
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