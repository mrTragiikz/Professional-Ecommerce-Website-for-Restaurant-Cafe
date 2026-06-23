<?php
/**
 * Forgot Password Handler
 */

require_once __DIR__ . '/../functions/security.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/email.php';

// Initialize session
initSecureSession();

$response = ['success' => false, 'error' => 'Invalid request'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic CSRF check could be here, but for simple AJAX start let's check action
    $action = $_POST['action'] ?? '';

    if ($action === 'send_otp') {
        $email = trim($_POST['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $response = ['success' => false, 'error' => 'Please enter a valid email address'];
        } else {
            // Check if user exists
            $user = findUserByEmail($email);

            if (!$user) {
                // Security: usually we shouldn't reveal if email exists, but user explicitly asked:
                // "if users type the email which is not in users database area then , ay invalid email ue correct email"
                $response = ['success' => false, 'error' => 'Invalid email, use correct email'];
            } else {
                // User exists, send OTP
                // Use existing function to generate and store OTP
                // Reuse existing OTP system which links to user_id

                $result = createVerificationOTP($user['id']);

                if ($result['success']) {
                    $otp = $result['otp'];

                    // Send Password Reset Email
                    $sendResult = sendPasswordResetEmail($email, $user['name'] ?? 'User', $otp);

                    if ($sendResult) {
                        $response = ['success' => true, 'message' => 'OTP sent to your email'];
                    } else {
                        $response = ['success' => false, 'error' => 'Failed to send OTP. Please try again.'];
                    }
                } else {
                    $response = ['success' => false, 'error' => 'System error generating OTP'];
                }
            }
        }
    } elseif ($action === 'verify_otp') {
        $email = trim($_POST['email'] ?? '');
        $otp = trim($_POST['otp'] ?? '');

        $user = findUserByEmail($email);

        if (!$user) {
            $response = ['success' => false, 'error' => 'Invalid email'];
        } else {
            // Verify OTP
            // verifyEmailOTP($otp, $userId)
            // Note: verifyEmailOTP marks it as used. This is fine.

            $verifyResult = verifyEmailOTP($otp, $user['id']);

            if ($verifyResult['success']) {
                // Success!
                // Set session to allow password reset
                $_SESSION['reset_password_user_id'] = $user['id'];
                $_SESSION['reset_password_verified'] = true;

                $response = ['success' => true, 'message' => 'OTP verified'];
            } else {
                $response = ['success' => false, 'error' => $verifyResult['error']];
            }
        }
    } elseif ($action === 'reset_password') {
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!isset($_SESSION['reset_password_user_id']) || !isset($_SESSION['reset_password_verified'])) {
            $response = ['success' => false, 'error' => 'Session expired. Please verify OTP again.'];
        } elseif ($password !== $confirmPassword) {
            $response = ['success' => false, 'error' => 'Passwords do not match'];
        } elseif (strlen($password) < 8) {
            $response = ['success' => false, 'error' => 'Password must be at least 8 characters'];
        } else {
            // Update password
            $userId = $_SESSION['reset_password_user_id'];
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            global $pdo;
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            if ($stmt->execute([$passwordHash, $userId])) {
                // Clear session
                unset($_SESSION['reset_password_user_id']);
                unset($_SESSION['reset_password_verified']);

                $response = ['success' => true, 'message' => 'Password changed successfully'];
            } else {
                $response = ['success' => false, 'error' => 'Database error updating password'];
            }
        }
    }
}

header('Content-Type: application/json');
echo json_encode($response);
exit;
