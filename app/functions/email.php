<?php
/**
 * Email Verification Functions
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/load_security.php';
require_once __DIR__ . '/security.php';

/**
 * Queue an email for background sending
 * 
 * @param string $toEmail Recipient email
 * @param string $toName Recipient name
 * @param string $subject Email subject
 * @param string $body Email body (HTML)
 * @return bool True if queued successfully
 */
function queueEmail($toEmail, $toName, $subject, $body)
{
    global $pdo;
    try {
        if (!isset($pdo)) {
            error_log("queueEmail: PDO not available");
            return false;
        }

        $stmt = $pdo->prepare("
            INSERT INTO email_queue (to_email, to_name, subject, body, status, created_at, updated_at) 
            VALUES (?, ?, ?, ?, 'pending', NOW(), NOW())
        ");

        $result = $stmt->execute([$toEmail, $toName, $subject, $body]);

        if ($result) {
            // error_log("Queued email to {$toEmail}");
            return true;
        } else {
            error_log("Failed to queue email to {$toEmail}: " . implode(" ", $stmt->errorInfo()));
            return false;
        }
    } catch (Exception $e) {
        error_log("Exception queuing email to {$toEmail}: " . $e->getMessage());
        return false;
    }
}

/**
 * Generate email verification OTP (6-digit code)
 * 
 * @return string 6-digit OTP code
 */
function generateVerificationOTP()
{
    return str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Create email verification OTP record
 * 
 * @param int $userId User ID
 * @param int $expiryMinutes Expiry time in minutes (default 10)
 * @return array ['success' => bool, 'otp' => string|null, 'error' => string|null]
 */
function createVerificationOTP($userId, $expiryMinutes = 10)
{
    global $pdo;

    try {
        // Check if database connection exists
        if (!isset($pdo) || $pdo === null) {
            error_log("Database connection not available in createVerificationOTP");
            // Still generate OTP and log it, even if DB fails
            $otp = generateVerificationOTP();
            $debugFile = __DIR__ . '/../../latest_otp.txt';
            file_put_contents($debugFile, "OTP Generated (DB Error): " . $otp . " (Time: " . date('Y-m-d H:i:s') . ")\n");
            return ['success' => false, 'otp' => $otp, 'error' => 'Database connection error'];
        }

        // Invalidate any existing unused OTPs
        $stmt = $pdo->prepare("
            UPDATE user_email_verifications 
            SET used_at = NOW() 
            WHERE user_id = ? AND used_at IS NULL
        ");
        $stmt->execute([$userId]);

        // Generate new OTP (6-digit code)
        $otp = generateVerificationOTP();
        $otpHash = hash('sha256', $otp);
        $expiresAt = date('Y-m-d H:i:s', time() + ($expiryMinutes * 60));

        // Insert verification record (using token_hash column to store OTP hash)
        $stmt = $pdo->prepare("
            INSERT INTO user_email_verifications (user_id, token_hash, expires_at)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$userId, $otpHash, $expiresAt]);

        // DEBUG: Log OTP to file for localhost debugging
        $debugFile = __DIR__ . '/../../latest_otp.txt';
        file_put_contents($debugFile, "Latest OTP: " . $otp . " (Time: " . date('Y-m-d H:i:s') . ")\nUser ID: " . $userId . "\n");

        error_log("✅ OTP generated successfully: {$otp} for user ID: {$userId}");
        return ['success' => true, 'otp' => $otp, 'error' => null];
    } catch (PDOException $e) {
        error_log("❌ Create verification OTP error: " . $e->getMessage());
        error_log("❌ SQL Error Code: " . $e->getCode());
        // Still generate OTP for debugging
        $otp = generateVerificationOTP();
        $debugFile = __DIR__ . '/../../latest_otp.txt';
        file_put_contents($debugFile, "OTP Generated (DB Exception): " . $otp . " (Time: " . date('Y-m-d H:i:s') . ")\nError: " . $e->getMessage() . "\n");
        return ['success' => false, 'otp' => $otp, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

/**
 * Verify email verification OTP
 * 
 * @param string $otp Verification OTP code
 * @param int|null $userId Optional user ID for additional validation
 * @return array ['success' => bool, 'user_id' => int|null, 'error' => string|null]
 */
function verifyEmailOTP($otp, $userId = null)
{
    global $pdo;

    // Validate OTP format (6 digits)
    if (!preg_match('/^\d{6}$/', $otp)) {
        return ['success' => false, 'user_id' => null, 'error' => 'Invalid OTP format. Please enter a 6-digit code.'];
    }

    $otpHash = hash('sha256', $otp);

    try {
        // Build query with optional user_id filter
        $query = "
            SELECT user_id, expires_at, used_at 
            FROM user_email_verifications 
            WHERE token_hash = ?
        ";
        $params = [$otpHash];

        if ($userId !== null) {
            $query .= " AND user_id = ?";
            $params[] = $userId;
        }

        $query .= " ORDER BY created_at DESC LIMIT 1";

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $verification = $stmt->fetch();

        if (!$verification) {
            return ['success' => false, 'user_id' => null, 'error' => 'Invalid OTP code. Please check and try again.'];
        }

        if ($verification['used_at']) {
            return ['success' => false, 'user_id' => null, 'error' => 'This OTP has already been used. Please request a new one.'];
        }

        /* 
        // Remove OTP expiry limit as per user request
        if (strtotime($verification['expires_at']) < time()) {
            return ['success' => false, 'user_id' => null, 'error' => 'This OTP has expired. Please request a new one.'];
        }
        */

        // Mark OTP as used
        $stmt = $pdo->prepare("
            UPDATE user_email_verifications 
            SET used_at = NOW() 
            WHERE token_hash = ?
        ");
        $stmt->execute([$otpHash]);

        // Mark user as verified
        $stmt = $pdo->prepare("UPDATE users SET is_verified = 1 WHERE id = ?");
        $stmt->execute([$verification['user_id']]);

        // Clear rate limit for this user's email (in case they were locked out)
        require_once __DIR__ . '/security.php';
        $userStmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $userStmt->execute([$verification['user_id']]);
        $user = $userStmt->fetch();
        if ($user) {
            resetRateLimit('login_attempt', $user['email']);
        }

        return ['success' => true, 'user_id' => $verification['user_id'], 'error' => null];
    } catch (PDOException $e) {
        error_log("Verify OTP error: " . $e->getMessage());
        return ['success' => false, 'user_id' => null, 'error' => 'Database error occurred'];
    }
}

// Backward compatibility aliases
function generateVerificationToken()
{
    return generateVerificationOTP();
}

function createVerificationToken($userId, $expiryHours = 24)
{
    $expiryMinutes = $expiryHours * 60;
    $result = createVerificationOTP($userId, $expiryMinutes);
    if ($result['success']) {
        return ['success' => true, 'token' => $result['otp'], 'error' => null];
    }
    return ['success' => false, 'token' => null, 'error' => $result['error']];
}

function verifyEmailToken($token)
{
    return verifyEmailOTP($token);
}

/**
 * Send verification email with OTP
 * 
 * @param string $email User email
 * @param string $name User name
 * @param string $otp Verification OTP code (6 digits)
 * @return bool True on success, false on failure
 */
function sendVerificationEmail($email, $name, $otp)
{
    // Get base path for verification page
    $basePath = '';
    if (function_exists('getBasePath')) {
        $basePath = getBasePath();
    } else {
        // Fallback: detect base path from SCRIPT_NAME
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if (preg_match('#^/([^/]+)/#', $scriptName, $matches)) {
            $firstDir = $matches[1];
            if (!in_array($firstDir, ['auth', 'app', 'css', 'js', 'assets', 'includes'])) {
                $basePath = '/' . $firstDir;
            }
        }
    }

    $verifyUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST'] . $basePath . '/auth/verify.php';

    $subject = 'Verify Your Email - Justkleek Food Delivery';
    $message = "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no'>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                background: #f5f7fa;
                padding: 15px 10px;
                line-height: 1.5;
            }
            .email-container {
                max-width: 500px;
                margin: 0 auto;
                background: #ffffff;
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            }
            .email-header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                padding: 20px 20px 18px;
                text-align: center;
                color: white;
            }
            .header-icon {
                width: 50px;
                height: 50px;
                margin: 0 auto 12px;
                background: rgba(255,255,255,0.2);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .header-icon svg {
                width: 28px;
                height: 28px;
            }
            .email-header h1 {
                font-size: 20px;
                font-weight: 700;
                margin: 0 0 6px 0;
                letter-spacing: -0.3px;
            }
            .email-header p {
                font-size: 13px;
                opacity: 0.95;
                margin: 0;
                font-weight: 400;
            }
            .email-content {
                padding: 20px 18px;
            }
            .greeting {
                font-size: 16px;
                color: #1a202c;
                margin-bottom: 12px;
                font-weight: 600;
            }
            .message {
                font-size: 13px;
                color: #4a5568;
                margin-bottom: 18px;
                line-height: 1.6;
            }
            .otp-container {
                text-align: center;
                margin: 20px 0;
                padding: 18px 12px;
                background: #f8f9fa;
                border-radius: 10px;
                border: 2px solid #e2e8f0;
            }
            .otp-code {
                font-size: 32px;
                font-weight: 700;
                color: #667eea;
                letter-spacing: 8px;
                font-family: 'Courier New', monospace;
                margin-bottom: 8px;
                word-spacing: 4px;
            }
            .otp-label {
                font-size: 11px;
                color: #64748b;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin: 0;
            }
            .info-box {
                background: #e0f2fe;
                border-left: 3px solid #0ea5e9;
                padding: 12px 14px;
                border-radius: 8px;
                margin: 12px 0;
            }
            .info-box p {
                color: #0c4a6e;
                font-size: 12px;
                line-height: 1.5;
                margin: 0;
            }
            .info-box strong {
                color: #075985;
                font-weight: 600;
            }
            .warning-box {
                background: #fef3c7;
                border-left: 3px solid #f59e0b;
                padding: 12px 14px;
                border-radius: 8px;
                margin: 12px 0;
            }
            .warning-box p {
                color: #92400e;
                font-size: 12px;
                line-height: 1.5;
                margin: 0;
            }
            .warning-box strong {
                font-weight: 600;
            }
            .email-footer {
                background: #1e293b;
                padding: 18px 16px;
                text-align: center;
                color: white;
            }
            .footer-logo {
                font-size: 16px;
                font-weight: 700;
                margin-bottom: 10px;
                color: white;
            }
            .footer-content {
                color: #cbd5e1;
                font-size: 11px;
                line-height: 1.6;
            }
            .footer-content p {
                margin: 4px 0;
            }
            .footer-copyright {
                margin-top: 12px;
                padding-top: 12px;
                border-top: 1px solid rgba(255,255,255,0.1);
                font-size: 10px;
                color: #94a3b8;
            }
            @media (max-width: 600px) {
                body {
                    padding: 10px 8px;
                }
                .email-container {
                    max-width: 100%;
                    border-radius: 10px;
                }
                .email-content {
                    padding: 16px 14px;
                }
                .email-header {
                    padding: 16px 16px 14px;
                }
                .email-header h1 {
                    font-size: 18px;
                }
                .email-header p {
                    font-size: 12px;
                }
                .header-icon {
                    width: 44px;
                    height: 44px;
                    margin-bottom: 10px;
                }
                .header-icon svg {
                    width: 24px;
                    height: 24px;
                }
                .greeting {
                    font-size: 15px;
                    margin-bottom: 10px;
                }
                .message {
                    font-size: 12px;
                    margin-bottom: 16px;
                }
                .otp-container {
                    padding: 14px 10px;
                    margin: 16px 0;
                }
                .otp-code {
                    font-size: 28px;
                    letter-spacing: 6px;
                    word-spacing: 3px;
                }
                .otp-label {
                    font-size: 10px;
                }
                .info-box, .warning-box {
                    padding: 10px 12px;
                    margin: 10px 0;
                }
                .info-box p, .warning-box p {
                    font-size: 11px;
                }
                .email-footer {
                    padding: 14px 12px;
                }
                .footer-logo {
                    font-size: 14px;
                    margin-bottom: 8px;
                }
                .footer-content {
                    font-size: 10px;
                }
                .footer-copyright {
                    font-size: 9px;
                    margin-top: 10px;
                    padding-top: 10px;
                }
            }
            @media (max-width: 400px) {
                .otp-code {
                    font-size: 24px;
                    letter-spacing: 4px;
                }
            }
        </style>
    </head>
    <body>
        <div class='email-container'>
            <div class='email-header'>
                <div class='header-icon'>
                    <svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2'>
                        <path d='M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z'/>
                        <polyline points='22,6 12,13 2,6'/>
                    </svg>
                </div>
                <h1>Verify Your Email</h1>
                <p>Justkleek Food Delivery</p>
            </div>
            <div class='email-content'>
                <div class='greeting'>Hello " . htmlspecialchars($name) . ",</div>
                <div class='message'>Thank you for creating an account! Please verify your email using the OTP code below.</div>
                
                <div class='otp-container'>
                    <div class='otp-code'>" . htmlspecialchars($otp) . "</div>
                    <p class='otp-label'>Verification Code</p>
                </div>
                
                <div class='info-box'>
                    <p><strong>📱 Where to Enter:</strong> Use this code in the OTP verification popup on our website.</p>
                </div>
                
                <div class='info-box'>
                    <p><strong>⏰ Expires:</strong> This code expires in <strong>10 minutes</strong>. Verify soon to activate your account.</p>
                </div>
                
                <div class='warning-box'>
                    <p><strong>🔒 Security:</strong> You must verify your email before logging in. If you didn't create this account, please ignore this email.</p>
                </div>
            </div>
            <div class='email-footer'>
                <div class='footer-logo'>🍽️ Justkleek Food Delivery</div>
                <div class='footer-content'>
                    <p>Bharatpur-11 , Bhojad, Nepal</p>
                    <p>Phone: +977 974-9705085 | Email: info@justkleek.com</p>
                </div>
                <div class='footer-copyright'>
                    &copy; " . date('Y') . " Justkleek Food Delivery. All rights reserved.
                    <div style='margin-top: 15px; padding-top: 15px; border-top: 1px dashed rgba(255,255,255,0.2); font-size: 11px; text-transform: uppercase;'>
                        <p style='margin: 0; font-weight: 700; color: #cbd5e1;'>Software by Prabin Sharma</p>
                        <p style='margin: 3px 0 0; color: #94a3b8; text-transform: none;'>Info- sharmaprabin160@gmail.com</p>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";

    // Send email synchronously (Reverted from queue)
    $emailConfig = getEmailConfig();
    return sendEmailViaSMTP($email, $name, $subject, $message, $emailConfig);
}

/**
 * Send Password Reset Email with OTP
 *
 * @param string $email User email
 * @param string $name User name
 * @param string $otp Reset OTP code
 * @return bool True on success
 */
function sendPasswordResetEmail($email, $name, $otp)
{
    // Get base path
    $basePath = '';
    if (function_exists('getBasePath')) {
        $basePath = getBasePath();
    } else {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if (preg_match('#^/([^/]+)/#', $scriptName, $matches)) {
            $firstDir = $matches[1];
            if (!in_array($firstDir, ['auth', 'app', 'css', 'js', 'assets', 'includes'])) {
                $basePath = '/' . $firstDir;
            }
        }
    }

    $subject = 'Reset Your Password - Justkleek Food Delivery';
    $message = "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no'>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
                background: #f5f7fa;
                padding: 15px 10px;
                line-height: 1.5;
            }
            .email-container {
                max-width: 500px;
                margin: 0 auto;
                background: #ffffff;
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            }
            .email-header {
                /* Use a slightly different gradient for Password Reset to distinguish it (e.g., Red/Orange or similar to login theme) */
                background: linear-gradient(135deg, #FF6B6B 0%, #EE5253 100%);
                padding: 20px 20px 18px;
                text-align: center;
                color: white;
            }
            .header-icon {
                width: 50px;
                height: 50px;
                margin: 0 auto 12px;
                background: rgba(255,255,255,0.2);
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .header-icon svg {
                width: 28px;
                height: 28px;
            }
            .email-header h1 {
                font-size: 20px;
                font-weight: 700;
                margin: 0 0 6px 0;
                letter-spacing: -0.3px;
            }
            .email-header p {
                font-size: 13px;
                opacity: 0.95;
                margin: 0;
                font-weight: 400;
            }
            .email-content {
                padding: 20px 18px;
            }
            .greeting {
                font-size: 16px;
                color: #1a202c;
                margin-bottom: 12px;
                font-weight: 600;
            }
            .message {
                font-size: 13px;
                color: #4a5568;
                margin-bottom: 18px;
                line-height: 1.6;
            }
            .otp-container {
                text-align: center;
                margin: 20px 0;
                padding: 18px 12px;
                background: #f8f9fa;
                border-radius: 10px;
                border: 2px solid #e2e8f0;
            }
            .otp-code {
                font-size: 32px;
                font-weight: 700;
                color: #FF6B6B; /* Match header */
                letter-spacing: 8px;
                font-family: 'Courier New', monospace;
                margin-bottom: 8px;
                word-spacing: 4px;
            }
            .otp-label {
                font-size: 11px;
                color: #64748b;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin: 0;
            }
            .info-box {
                background: #e0f2fe;
                border-left: 3px solid #0ea5e9;
                padding: 12px 14px;
                border-radius: 8px;
                margin: 12px 0;
            }
            .info-box p {
                color: #0c4a6e;
                font-size: 12px;
                line-height: 1.5;
                margin: 0;
            }
            .info-box strong {
                color: #075985;
                font-weight: 600;
            }
            .warning-box {
                background: #fef3c7;
                border-left: 3px solid #f59e0b;
                padding: 12px 14px;
                border-radius: 8px;
                margin: 12px 0;
            }
            .warning-box p {
                color: #92400e;
                font-size: 12px;
                line-height: 1.5;
                margin: 0;
            }
            .warning-box strong {
                font-weight: 600;
            }
            .email-footer {
                background: #1e293b;
                padding: 18px 16px;
                text-align: center;
                color: white;
            }
            .footer-logo {
                font-size: 16px;
                font-weight: 700;
                margin-bottom: 10px;
                color: white;
            }
            .footer-content {
                color: #cbd5e1;
                font-size: 11px;
                line-height: 1.6;
            }
            .footer-content p {
                margin: 4px 0;
            }
            .footer-copyright {
                margin-top: 12px;
                padding-top: 12px;
                border-top: 1px solid rgba(255,255,255,0.1);
                font-size: 10px;
                color: #94a3b8;
            }
            @media (max-width: 600px) {
                body { padding: 10px 8px; }
                .email-container { max-width: 100%; border-radius: 10px; }
            }
        </style>
    </head>
    <body>
        <div class='email-container'>
            <div class='email-header'>
                <div class='header-icon'>
                    <svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'>
                        <rect x='3' y='11' width='18' height='11' rx='2' ry='2'></rect>
                        <path d='M7 11V7a5 5 0 0 1 10 0v4'></path>
                    </svg>
                </div>
                <h1>Reset Password</h1>
                <p>Justkleek Food Delivery</p>
            </div>
            <div class='email-content'>
                <div class='greeting'>Hello " . htmlspecialchars($name) . ",</div>
                <div class='message'>We received a request to reset your password. Please use the OTP code below to set a new password.</div>
                
                <div class='otp-container'>
                    <div class='otp-code'>" . htmlspecialchars($otp) . "</div>
                    <p class='otp-label'>Password Reset Code</p>
                </div>
                
                <div class='info-box'>
                    <p><strong>⏰ Expires:</strong> This code is valid for <strong>10 minutes</strong>.</p>
                </div>
                
                <div class='warning-box'>
                    <p><strong>🔒 Security:</strong> If you did not request a password reset, you can safely ignore this email. Your account remains secure.</p>
                </div>
            </div>
            <div class='email-footer'>
                <div class='footer-logo'>🍽️ Justkleek Food Delivery</div>
                <div class='footer-content'>
                    <p>Bharatpur-11 , Bhojad, Nepal</p>
                    <p>Phone: +977 974-9705085 | Email: info@justkleek.com</p>
                </div>
                <div class='footer-copyright'>
                    &copy; " . date('Y') . " Justkleek Food Delivery. All rights reserved.
                    <div style='margin-top: 15px; padding-top: 15px; border-top: 1px dashed rgba(255,255,255,0.2); font-size: 11px; text-transform: uppercase;'>
                        <p style='margin: 0; font-weight: 700; color: #cbd5e1;'>Software by Prabin Sharma</p>
                        <p style='margin: 3px 0 0; color: #94a3b8; text-transform: none;'>Info- sharmaprabin160@gmail.com</p>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    ";

    // Send email synchronously (Reverted from queue)
    $emailConfig = getEmailConfig();
    return sendEmailViaSMTP($email, $name, $subject, $message, $emailConfig);
}

/**
 * Send email via SMTP using PHPMailer (primary) or fallback socket method
 * 
 * PHPMailer handles proper RFC-compliant headers, MIME encoding, and SMTP 
 * conversation which prevents spam filter rejections (550 High probability of spam).
 */
function sendEmailViaSMTP($to, $toName, $subject, $htmlMessage, $config)
{
    $smtpHost = $config['smtp_host'] ?? 'mail.justkleek.com';
    $smtpPort = $config['smtp_port'] ?? 465;
    $smtpUsername = $config['smtp_username'] ?? '';
    $smtpPassword = $config['smtp_password'] ?? '';
    $smtpEncryption = $config['smtp_encryption'] ?? 'ssl';
    $fromEmail = $config['from_email'] ?? $smtpUsername;
    $fromName = $config['from_name'] ?? 'JustKleek';
    $replyTo = $config['reply_to'] ?? $fromEmail;

    if (empty($smtpUsername) || empty($smtpPassword)) {
        error_log("SMTP credentials not configured.");
        return false;
    }

    // === PRIMARY: Use PHPMailer if available ===
    $phpmailerPath = __DIR__ . '/../../PHPMailer/src/PHPMailer.php';
    if (file_exists($phpmailerPath)) {
        return sendEmailViaPHPMailer(
            $to, $toName, $subject, $htmlMessage,
            $smtpHost, $smtpPort, $smtpUsername, $smtpPassword,
            $smtpEncryption, $fromEmail, $fromName, $replyTo
        );
    }

    // === FALLBACK: Use raw socket method ===
    error_log("PHPMailer not found at {$phpmailerPath}, using socket fallback");
    return sendEmailViaSocket(
        $to, $toName, $subject, $htmlMessage,
        $smtpHost, $smtpPort, $smtpUsername, $smtpPassword,
        $smtpEncryption, $fromEmail, $fromName, $replyTo
    );
}

/**
 * Send email using the PHPMailer library (RFC-compliant, anti-spam safe)
 * 
 * This method properly handles:
 * - RFC 2822 compliant headers (Date, Message-ID, MIME-Version)
 * - Proper MIME multipart structure with text/plain fallback 
 * - Correct Content-Transfer-Encoding (quoted-printable)
 * - Proper SMTP conversation with EHLO hostname
 * - All of which are critical for passing spam filters
 */
function sendEmailViaPHPMailer($to, $toName, $subject, $htmlMessage, $host, $port, $username, $password, $encryption, $fromEmail, $fromName, $replyTo = null)
{
    // Load PHPMailer classes
    $basePath = __DIR__ . '/../../PHPMailer/src';
    require_once $basePath . '/Exception.php';
    require_once $basePath . '/PHPMailer.php';
    require_once $basePath . '/SMTP.php';

    $debugFile = __DIR__ . '/../../smtp_debug.txt';
    $debugLog = ["--- PHPMailer Attempt " . date('Y-m-d H:i:s') . " ---"];
    $debugLog[] = "To: {$to} | Subject: {$subject}";

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $username;
        $mail->Password   = $password;
        $mail->SMTPSecure = ($encryption === 'ssl') 
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS 
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $port;
        $mail->CharSet    = \PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
        $mail->Encoding   = \PHPMailer\PHPMailer\PHPMailer::ENCODING_QUOTED_PRINTABLE;

        // Disable SSL verification for local dev (cPanel self-signed certs)
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        // Timeout
        $mail->Timeout = 30;

        // From / Reply-To
        $mail->setFrom($fromEmail, $fromName);
        if ($replyTo) {
            $mail->addReplyTo($replyTo, $fromName);
        }

        // Recipient
        $mail->addAddress($to, $toName);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlMessage;

        // Generate a plain-text version automatically (helps spam score)
        $mail->AltBody = strip_tags(
            str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $htmlMessage)
        );

        // Custom headers for better deliverability
        $mail->XMailer = 'JustKleek Mailer 2.0 (PHPMailer)';

        // Debug output to our log file
        $mail->SMTPDebug = 2; // Set to 2 for full debug in smtp_debug.txt
        $mail->Debugoutput = function($str, $level) use (&$debugLog) {
            $debugLog[] = "PHPMailer [{$level}]: " . trim($str);
        };

        // Send!
        $result = $mail->send();

        $debugLog[] = "SUCCESS: Email sent via PHPMailer to {$to}";
        error_log("PHPMailer: Email sent successfully to {$to}");
        file_put_contents($debugFile, implode("\n", $debugLog) . "\n\n", FILE_APPEND);
        return true;

    } catch (\PHPMailer\PHPMailer\Exception $e) {
        $debugLog[] = "PHPMAILER ERROR: " . $mail->ErrorInfo;
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        file_put_contents($debugFile, implode("\n", $debugLog) . "\n\n", FILE_APPEND);
        return false;
    } catch (\Exception $e) {
        $debugLog[] = "GENERAL ERROR: " . $e->getMessage();
        error_log("Email Error: " . $e->getMessage());
        file_put_contents($debugFile, implode("\n", $debugLog) . "\n\n", FILE_APPEND);
        return false;
    }
}

/**
 * LEGACY FALLBACK: Simple SMTP email sending using raw sockets
 * Used only when PHPMailer library is not installed.
 */
function sendEmailViaSocket($to, $toName, $subject, $htmlMessage, $host, $port, $username, $password, $encryption, $fromEmail, $fromName, $replyTo = null)
{
    $errorLog = [];

    // For TLS, we need to use stream_socket_client instead of fsockopen
    if ($encryption === 'tls') {
        $contextOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
        $context = stream_context_create($contextOptions);
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
    } else {
        $socket = @fsockopen(($encryption === 'ssl' ? 'ssl://' : '') . $host, $port, $errno, $errstr, 30);
    }

    // DEBUG: Start logging
    $debugFile = __DIR__ . '/../../smtp_debug.txt';
    $debugLog = ["--- SMTP Socket Fallback " . date('Y-m-d H:i:s') . " ---"];
    $debugLog[] = "Connecting to {$host}:{$port} (" . ($encryption) . ")";

    if (!$socket) {
        $errorMsg = "SMTP connection failed: {$errstr} ({$errno})";
        error_log($errorMsg);
        $debugLog[] = "CONNECTION FAILED: " . $errorMsg;
        file_put_contents($debugFile, implode("\n", $debugLog) . "\n\n", FILE_APPEND);
        return false;
    }

    // Set timeout
    stream_set_timeout($socket, 30);

    // Helper to log and read
    $readResponse = function () use ($socket, &$debugLog) {
        $response = fgets($socket, 515);
        $debugLog[] = "SERVER: " . trim($response);
        return $response;
    };

    $sendCommand = function ($cmd, $hide = false) use ($socket, &$debugLog) {
        fputs($socket, $cmd . "\r\n");
        $debugLog[] = "CLIENT: " . ($hide ? "******" : $cmd);
    };

    // Read server greeting
    $greetingOk = false;
    while ($line = fgets($socket, 515)) {
        $debugLog[] = "SERVER: " . trim($line);
        $code = substr($line, 0, 3);
        $separator = substr($line, 3, 1);
        if ($code === '220' && $separator === ' ') {
            $greetingOk = true;
            break;
        } elseif ($code !== '220') {
            error_log("SMTP: Invalid server greeting - " . trim($line));
            fclose($socket);
            file_put_contents($debugFile, implode("\n", $debugLog) . "\n\n", FILE_APPEND);
            return false;
        }
    }
    if (!$greetingOk) {
        error_log("SMTP: Server greeting not received");
        fclose($socket);
        file_put_contents($debugFile, implode("\n", $debugLog) . "\n\n", FILE_APPEND);
        return false;
    }

    // Send EHLO
    $sendCommand("EHLO {$host}", false);
    $response = '';
    while ($line = fgets($socket, 515)) {
        $response .= $line;
        $debugLog[] = "SERVER: " . trim($line);
        if (substr($line, 3, 1) === ' ')
            break;
    }

    // Start TLS if needed
    if ($encryption === 'tls') {
        $sendCommand("STARTTLS", false);
        $response = $readResponse();
        if (substr($response, 0, 3) !== '220') {
            error_log("SMTP: STARTTLS failed - " . trim($response));
            fclose($socket);
            file_put_contents($debugFile, implode("\n", $debugLog) . "\n\n", FILE_APPEND);
            return false;
        }

        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            $debugLog[] = "ERROR: TLS handshake failed";
            error_log("SMTP: TLS encryption failed");
            fclose($socket);
            file_put_contents($debugFile, implode("\n", $debugLog) . "\n\n", FILE_APPEND);
            return false;
        }

        $sendCommand("EHLO {$host}", false);
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            $debugLog[] = "SERVER (Secure): " . trim($line);
            if (substr($line, 3, 1) === ' ')
                break;
        }
    }

    // Authenticate
    $sendCommand("AUTH LOGIN", false);
    $response = $readResponse();
    if (substr($response, 0, 3) !== '334') {
        error_log("SMTP: AUTH LOGIN failed");
        fclose($socket);
        file_put_contents($debugFile, implode("\n", $debugLog) . "\n\n", FILE_APPEND);
        return false;
    }

    $sendCommand(base64_encode($username), true);
    $response = $readResponse();

    $sendCommand(base64_encode($password), true);
    $response = $readResponse();

    if (substr($response, 0, 3) !== '235') {
        error_log("SMTP authentication failed");
        fclose($socket);
        file_put_contents($debugFile, implode("\n", $debugLog) . "\n\n", FILE_APPEND);
        return false;
    }

    // Send email
    $sendCommand("MAIL FROM: <{$fromEmail}>", false);
    $response = $readResponse();
    if (substr($response, 0, 3) !== '250') {
        error_log("SMTP: MAIL FROM failed");
        fclose($socket);
        file_put_contents($debugFile, implode("\n", $debugLog) . "\n\n", FILE_APPEND);
        return false;
    }

    $sendCommand("RCPT TO: <{$to}>", false);
    $response = $readResponse();
    if (substr($response, 0, 3) !== '250') {
        error_log("SMTP: RCPT TO failed");
        fclose($socket);
        file_put_contents($debugFile, implode("\n", $debugLog) . "\n\n", FILE_APPEND);
        return false;
    }

    $sendCommand("DATA", false);
    $response = $readResponse();
    if (substr($response, 0, 3) !== '354') {
        error_log("SMTP: DATA command failed");
        fclose($socket);
        file_put_contents($debugFile, implode("\n", $debugLog) . "\n\n", FILE_APPEND);
        return false;
    }

    $date = date('r');
    $messageId = sprintf("<%s.%s@%s>", 
        base_convert(microtime(true) * 10000, 10, 36), 
        base_convert(bin2hex(random_bytes(8)), 16, 36), 
        $host
    );

    $emailHeaders = "Date: {$date}\r\n";
    $emailHeaders .= "From: {$fromName} <{$fromEmail}>\r\n";
    if ($replyTo) {
        $emailHeaders .= "Reply-To: {$replyTo}\r\n";
    }
    $emailHeaders .= "To: {$toName} <{$to}>\r\n";
    $emailHeaders .= "Subject: {$subject}\r\n";
    $emailHeaders .= "Message-ID: {$messageId}\r\n";
    $emailHeaders .= "X-Mailer: JustKleek Mailer/1.0\r\n";
    $emailHeaders .= "MIME-Version: 1.0\r\n";
    $emailHeaders .= "Content-Type: text/html; charset=UTF-8\r\n";
    $emailHeaders .= "Content-Transfer-Encoding: base64\r\n";
    $emailHeaders .= "\r\n";

    $base64Body = chunk_split(base64_encode($htmlMessage), 76, "\r\n");

    fputs($socket, $emailHeaders . $base64Body . "\r\n.\r\n");
    $debugLog[] = "CLIENT: [Email Body Sent (Base64 Encoded)]";

    $response = $readResponse();

    if (substr($response, 0, 3) !== '250') {
        error_log("SMTP: Email sending failed - " . trim($response));
        fclose($socket);
        file_put_contents($debugFile, implode("\n", $debugLog) . "\n\n", FILE_APPEND);
        return false;
    }

    $sendCommand("QUIT", false);
    fclose($socket);

    error_log("SMTP email sent successfully to {$to}");
    $debugLog[] = "SUCCESS: Email accepted by server";
    file_put_contents($debugFile, implode("\n", $debugLog) . "\n\n", FILE_APPEND);
    return true;
}

/**
 * Send reservation inquiry email to admin
 */
/**
 * Send reservation inquiry email to admin
 */


/**
 * Send order notification email to admin
 */
function sendOrderToAdminEmail($orderData, $user)
{
    // Resolve admin email
    $emailConfig = getEmailConfig();
    $adminEmail = $emailConfig['admin_email'] ?? ($emailConfig['from_email'] ?? 'info@justkleek.com');

    $orderId = isset($orderData['display_id']) ? $orderData['display_id'] : '#' . substr($orderData['order_id'], 0, 8);
    $customerName = isset($user['name']) ? $user['name'] : (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $customerEmail = $user['email'];
    $customerPhone = $user['phone'] ?? 'N/A';

    $subject = "New Order Alert! - " . $orderId;

    $serviceType = ucfirst($orderData['service_type']);
    $total = number_format($orderData['total'] ?? 0, 2);

    // Build items HTML nicely
    $itemsHtml = '';
    foreach ($orderData['items'] as $item) {
        $itemTotal = number_format($item['price'] * $item['quantity'], 2);
        $itemsHtml .= "
        <tr>
            <td style='padding: 12px 0; border-bottom: 1px solid #f0f0f0; color: #333;'>
                <strong style='font-size: 14px;'>{$item['name']}</strong>
                <div style='font-size: 12px; color: #888;'>Qty: {$item['quantity']}</div>
            </td>
            <td style='padding: 12px 0; border-bottom: 1px solid #f0f0f0; text-align: right; color: #333; font-weight: 500;'>
                Rs. {$itemTotal}
            </td>
        </tr>";
    }

    $message = "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <style>
            body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f4f6f8; margin: 0; padding: 0; -webkit-font-smoothing: antialiased; }
            .wrapper { width: 100%; background-color: #f4f6f8; padding: 40px 0; }
            .container { max-width: 500px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
            .header { background: #1e293b; padding: 30px 20px; text-align: center; color: white; }
            .content { padding: 30px; }
            .order-card { background: #fafafa; border-radius: 12px; padding: 20px; text-align: left; margin: 20px 0; border: 1px solid #eee; }
            h1 { margin: 0; font-size: 22px; font-weight: 700; color: #FFA53B; }
            .subtitle { margin: 5px 0 0; opacity: 0.8; font-size: 14px; color: #fff; }
            .section-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #888; margin-bottom: 10px; margin-top: 20px; }
            .info-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 14px; }
            .info-label { color: #666; }
            .info-value { font-weight: 600; color: #333; text-align: right; }
            .status-badge { display: inline-block; padding: 4px 10px; background: #FFF3E0; color: #E65100; border-radius: 4px; font-size: 11px; font-weight: 700; text-transform: uppercase; float: right; margin-top: -3px;}
            .btn { display: block; width: 100%; text-align: center; padding: 14px 0; background-color: #1e293b; color: white !important; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 14px; margin-top: 20px; }
            .footer { background: #f9f9f9; padding: 20px; text-align: center; font-size: 12px; color: #999; border-top: 1px solid #eee; }
        </style>
    </head>
    <body>
        <div class='wrapper'>
            <div class='container'>
                <div class='header'>
                    <h1>New Order Received</h1>
                    <p class='subtitle'>Order {$orderId}</p>
                </div>
                
                <div class='content'>
                    <div style='background: #fff8f0; border: 1px solid #ffe0b2; border-radius: 8px; padding: 15px; margin-bottom: 25px;'>
                        <div class='info-row'>
                            <span class='info-label'>Customer</span>
                            <span class='info-value'>{$customerName}</span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>Phone</span>
                            <span class='info-value'><a href='tel:{$customerPhone}' style='color: #2196f3; text-decoration: none;'>{$customerPhone}</a></span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>Email</span>
                            <span class='info-value'><a href='mailto:{$customerEmail}' style='color: #2196f3; text-decoration: none;'>{$customerEmail}</a></span>
                        </div>
                        <div class='info-row'>
                            <span class='info-label'>Service</span>
                            <span class='info-value' style='color: #FFA53B;'>{$serviceType}</span>
                        </div>
                        </div>
                    </div>
                
                    <div class='section-title'>Order Details <span class='status-badge'>New</span></div>
                    
                    <div class='order-card'>
                        <table width='100%' cellspacing='0'>
                            {$itemsHtml}
                        </table>
                        
                        <div style='margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center;'>
                            <span style='font-weight: 600; color: #333;'>Total Amount</span>
                            <span style='font-size: 20px; font-weight: 700; color: #1e293b;'>Rs. {$total}</span>
                        </div>
                    </div>
                    
                    <a href='mailto:{$customerEmail}' class='btn'>Reply to Customer</a>
                </div>
                
                <div class='footer'>
                    <p style='margin: 0;'>Justkleek Admin Notification System</p>
                    <div style='margin-top: 15px; padding-top: 15px; border-top: 1px dashed #ddd; font-size: 11px; text-transform: uppercase;'>
                        <p style='margin: 0; font-weight: 700; color: #777;'>Software by Prabin Sharma</p>
                        <p style='margin: 3px 0 0; color: #999; text-transform: none;'>Info- sharmaprabin160@gmail.com</p>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>";


    // Send email synchronously (Reverted from queue)
    $emailConfig = getEmailConfig();
    return sendEmailViaSMTP($adminEmail, "Admin", $subject, $message, $emailConfig);
}

/**
 * Send auto-reply confirmation email to customer
 */
function sendOrderConfirmationToCustomer($orderData, $user)
{
    $orderId = isset($orderData['display_id']) ? $orderData['display_id'] : '#' . substr($orderData['order_id'], 0, 8);
    $customerName = isset($user['name']) ? $user['name'] : (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
    $customerEmail = $user['email'];

    // Use hardcoded production URL for email links to prevent incorrect URLs
    $trackingUrl = 'https://justkleek.com/order-tracking.php';

    $subject = "We've got your order! - " . $orderId;

    $serviceType = ucfirst($orderData['service_type']);
    $total = number_format($orderData['total'] ?? 0, 2);

    // Build items HTML nicely
    $itemsHtml = '';
    foreach ($orderData['items'] as $item) {
        $itemTotal = number_format($item['price'] * $item['quantity'], 2);
        $itemsHtml .= "
        <tr>
            <td style='padding: 12px 0; border-bottom: 1px solid #f0f0f0; color: #333;'>
                <strong style='font-size: 14px;'>{$item['name']}</strong>
                <div style='font-size: 12px; color: #888;'>Qty: {$item['quantity']}</div>
            </td>
            <td style='padding: 12px 0; border-bottom: 1px solid #f0f0f0; text-align: right; color: #333; font-weight: 500;'>
                Rs. {$itemTotal}
            </td>
        </tr>";
    }

    $message = "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <style>
            body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f4f6f8; margin: 0; padding: 0; -webkit-font-smoothing: antialiased; }
            .wrapper { width: 100%; background-color: #f4f6f8; padding: 40px 0; }
            .container { max-width: 500px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
            .header { background: linear-gradient(135deg, #FFA53B 0%, #FF8C00 100%); padding: 30px 20px; text-align: center; color: white; }
            .content { padding: 30px; text-align: center; }
            .order-card { background: #fafafa; border-radius: 12px; padding: 20px; text-align: left; margin: 20px 0; border: 1px solid #eee; }
            h1 { margin: 0; font-size: 24px; font-weight: 700; }
            p { color: #555; line-height: 1.6; font-size: 15px; margin-bottom: 15px; }
            .btn { display: inline-block; padding: 14px 28px; background-color: #FFA53B; color: white !important; text-decoration: none; border-radius: 50px; font-weight: bold; font-size: 15px; box-shadow: 0 4px 10px rgba(255, 165, 59, 0.3); transition: transform 0.2s; }
            .btn:hover { transform: translateY(-2px); box-shadow: 0 6px 15px rgba(255, 165, 59, 0.4); }
            .footer { background: #f9f9f9; padding: 20px; text-align: center; font-size: 12px; color: #999; border-top: 1px solid #eee; }
            .status-badge { display: inline-block; padding: 6px 12px; background: #e3f2fd; color: #2196f3; border-radius: 20px; font-size: 12px; font-weight: 600; margin-bottom: 20px; }
        </style>
    </head>
    <body>
        <div class='wrapper'>
            <div class='container'>
                <div class='header'>
                    <h1>Order Received!</h1>
                </div>
                
                <div class='content'>
                    <div class='status-badge'>Status: Under Review</div>
                    
                    <p style='font-size: 18px; color: #333; font-weight: 600;'>Hello {$customerName},</p>
                    <p>Thanks for ordering from Justkleek! 🧡</p>
                    <p>Your order is currently being checked by our team. <strong>We will acknowledge and call you shortly to confirm the details.</strong></p>
                    
                    <div class='order-card'>
                        <div style='display: flex; justify-content: space-between; margin-bottom: 15px; border-bottom: 1px solid #ddd; padding-bottom: 10px;'>
                            <span style='color: #888; font-size: 12px;'>Order ID</span>
                            <strong style='color: #333;'>{$orderId}</strong>
                        </div>
                        
                        <table width='100%' cellspacing='0'>
                            {$itemsHtml}
                        </table>
                        
                        <div style='margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center;'>
                            <span style='font-weight: 600; color: #333;'>Total</span>
                            <span style='font-size: 18px; font-weight: 700; color: #FFA53B;'>Rs. {$total}</span>
                        </div>
                    </div>
                    
                    <p>Sit back and relax! We'll be in touch soon.</p>
                    
                    <a href='{$trackingUrl}' class='btn'>Track Your Order</a>
                </div>
                
                <div class='footer'>
                    <p style='margin: 0;'>Justkleek Food Delivery &bull; Bharatpur, Nepal</p>
                    <p style='margin: 5px 0 0;'>Need help? Call us at +977 974-9705085</p>
                    <div style='margin-top: 15px; padding-top: 15px; border-top: 1px dashed #ddd; font-size: 11px; text-transform: uppercase;'>
                        <p style='margin: 0; font-weight: 700; color: #777;'>Software by Prabin Sharma</p>
                        <p style='margin: 3px 0 0; color: #999; text-transform: none;'>Info- sharmaprabin160@gmail.com</p>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>";


    // Send email synchronously (Reverted from queue)
    $emailConfig = getEmailConfig();
    return sendEmailViaSMTP($customerEmail, $customerName, $subject, $message, $emailConfig);
}

/**
 * Send Admin PIN Reset Email with OTP
 */
function sendAdminPinResetEmail($email, $otp)
{
    // Get base path for logo
    $basePath = '';
    if (function_exists('getBasePath')) {
        $basePath = getBasePath();
    } else {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if (preg_match('#^/([^/]+)/#', $scriptName, $matches)) {
            $firstDir = $matches[1];
            if (!in_array($firstDir, ['auth', 'admin', 'app', 'css', 'js', 'assets', 'includes'])) {
                $basePath = '/' . $firstDir;
            }
        }
    }
    
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $logoUrl = $protocol . '://' . $host . $basePath . '/assets/logo.png';

    $subject = '🔒 Admin Security Verification Code';
    
    // Use INLINE STYLES for maximum compatibility and centering
    $message = "
    <!DOCTYPE html>
    <html lang='en'>
    <head><meta charset='UTF-8'></head>
    <body style='background-color: #f4f7f9; padding: 40px 15px; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; margin: 0;'>
        <div style='max-width: 500px; margin: 0 auto; background: #ffffff; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 25px rgba(0,0,0,0.06); border: 1px solid #eef2f5;'>
            
            <!-- Header -->
            <div style='padding: 40px 20px 0; text-align: center;'>
                <img src='{$logoUrl}' alt='Justkleek' style='max-height: 65px; width: auto; margin-bottom: 25px;'>
                <h1 style='font-size: 24px; font-weight: 700; color: #1e293b; margin: 0 0 15px; text-align: center;'>Security Verification</h1>
            </div>

            <!-- Content -->
            <div style='padding: 0 40px 40px; text-align: center;'>
                <p style='font-size: 15px; color: #64748b; line-height: 1.6; margin: 0 0 30px; text-align: center;'>
                    A request was made to reset your Admin PIN. Enter the code below to authorize this change.
                </p>
                
                <div style='background: #f8fafc; border: 2px dashed #e2e8f0; border-radius: 14px; padding: 30px; margin: 20px 0; text-align: center;'>
                    <div style='font-size: 44px; font-weight: 800; color: #2563eb; letter-spacing: 12px; font-family: \"Courier New\", monospace; margin-left: 12px;'>{$otp}</div>
                </div>
                
                <!-- Security Warning -->
                <div style='background: #fff5f5; border: 1px solid #feb2b2; padding: 18px; border-radius: 10px; margin-top: 30px; text-align: center;'>
                    <div style='font-size: 13px; color: #c53030; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px;'>⚠️ Critical Security Warning</div>
                    <div style='font-size: 13px; color: #742a2a; line-height: 1.5;'>
                        <strong>DO NOT SHARE</strong> this code with anyone. Justkleek staff will NEVER ask for this PIN or verification code over phone or message.
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div style='background: #ffffff; padding: 35px 20px; text-align: center; border-top: 1px solid #f1f5f9;'>
                <div style='font-weight: 700; color: #1e293b; font-size: 14px; margin-bottom: 5px;'>Justkleek Food Delivery</div>
                <div style='color: #64748b; font-size: 12px;'>Bharatpur-11, Bhojad, Nepal</div>
                <div style='color: #94a3b8; font-size: 12px; margin: 10px 0;'>info@justkleek.com | +977 974-9705085</div>
                
                <div style='margin-top: 25px; padding-top: 20px; border-top: 1px solid #f1f5f9;'>
                    <div style='font-weight: 700; color: #475569; font-size: 11px; text-transform: uppercase; letter-spacing: 0.8px;'>Software by Prabin Sharma</div>
                    <div style='color: #94a3b8; font-size: 11px; margin-top: 4px;'>info - sharmaprabin160@gmail.com</div>
                </div>
                
                <div style='margin-top: 30px; font-size: 10px; color: #cbd5e1;'>
                    &copy; " . date('Y') . " Justkleek. All rights reserved.
                </div>
            </div>
        </div>
    </body>
    </html>
    ";

    $emailConfig = getEmailConfig();
    return sendEmailViaSMTP($email, 'Administrator', $subject, $message, $emailConfig);
}

/**
 * Send Security Alert Email for failed PIN attempts
 */
function sendAdminSecurityAlertEmail($email, $attempts)
{
    // Get base path for logo
    $basePath = '';
    if (function_exists('getBasePath')) {
        $basePath = getBasePath();
    } else {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if (preg_match('#^/([^/]+)/#', $scriptName, $matches)) {
            $firstDir = $matches[1];
            if (!in_array($firstDir, ['auth', 'admin', 'app', 'css', 'js', 'assets', 'includes'])) {
                $basePath = '/' . $firstDir;
            }
        }
    }
    
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $logoUrl = $protocol . '://' . $host . $basePath . '/assets/logo.png';

    $subject = 'Security Alert: Multiple Failed Attempts';
    $message = "
    <!DOCTYPE html>
    <html lang='en'>
    <head><meta charset='UTF-8'></head>
    <body style='background-color: #fcfcfc; padding: 50px 15px; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; margin: 0;'>
        <div style='max-width: 520px; margin: 0 auto; background: #ffffff; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.04); border: 1px solid #f0f0f0;'>
            
            <!-- Branding -->
            <div style='padding: 50px 20px 0; text-align: center;'>
                <img src='{$logoUrl}' alt='Justkleek' style='max-height: 55px; width: auto; margin-bottom: 30px;'>
                <h1 style='font-size: 26px; font-weight: 700; color: #b91c1c; margin: 0 0 10px; text-align: center;'>Security Alert</h1>
                <div style='display: inline-block; background: #fee2e2; color: #991b1b; padding: 6px 14px; border-radius: 30px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;'>Unauthorized Access Detected</div>
            </div>

            <!-- Main Message -->
            <div style='padding: 40px 40px 50px; text-align: center;'>
                <p style='font-size: 16px; color: #4b5563; line-height: 1.7; margin: 0 0 35px; text-align: center;'>
                    Our cloud security system has intercepted <strong>{$attempts} consecutive failed PIN attempts</strong> on your administrator account.
                </p>
                
                <!-- Incident Summary Table -->
                <div style='background: #fafafa; border: 1px solid #f0f0f0; border-radius: 16px; padding: 25px; text-align: left;'>
                    <div style='font-size: 14px; color: #111827; font-weight: 700; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;'>Incident Summary</div>
                    
                    <div style='margin-bottom: 10px; display: block;'>
                        <span style='font-size: 12px; color: #6b7280; width: 100px; display: inline-block;'>Threshold:</span>
                        <span style='font-size: 13px; color: #b91c1c; font-weight: 600;'>Exhausted ({$attempts}/5)</span>
                    </div>
                    
                    <div style='margin-bottom: 10px; display: block;'>
                        <span style='font-size: 12px; color: #6b7280; width: 100px; display: inline-block;'>IP Address:</span>
                        <span style='font-size: 13px; color: #374151; font-family: monospace;'>{$_SERVER['REMOTE_ADDR']}</span>
                    </div>
                    
                    <div style='display: block;'>
                        <span style='font-size: 12px; color: #6b7280; width: 100px; display: inline-block; vertical-align: top;'>Platform:</span>
                        <span style='font-size: 12px; color: #374151; line-height: 1.4; display: inline-block; width: 230px;'>{$_SERVER['HTTP_USER_AGENT']}</span>
                    </div>
                </div>

                <p style='font-size: 14px; color: #9ca3af; margin-top: 35px; font-style: italic; text-align: center;'>
                    If this wasn't you, your account may be at risk. Reset your admin credentials immediately.
                </p>
            </div>

            <!-- Footer Branding & Credits -->
            <div style='background: #fdfdfd; padding: 45px 20px; text-align: center; border-top: 1px solid #f8f8f8;'>
                <div style='font-weight: 700; color: #1f2937; font-size: 14px; letter-spacing: 0.5px;'>JUSTKLEEK SECURITY SYSTEMS</div>
                
                <div style='margin-top: 30px; padding-top: 25px; border-top: 1px solid #f3f4f6;'>
                    <div style='font-weight: 700; color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: 1px;'>Developed by Prabin Sharma</div>
                    <div style='color: #94a3b8; font-size: 11px; margin-top: 5px;'>Direct Support: sharmaprabin160@gmail.com</div>
                </div>
                
                <div style='margin-top: 35px; font-size: 10px; color: #d1d5db;'>
                    &copy; " . date('Y') . " Justkleek Enterprise. All rights reserved.
                </div>
            </div>
        </div>
    </body>
    </html>
    ";

    $emailConfig = getEmailConfig();
    return sendEmailViaSMTP($email, 'Administrator', $subject, $message, $emailConfig);
}
