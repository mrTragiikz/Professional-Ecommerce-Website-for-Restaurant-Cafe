<?php
/**
 * Forgot Password Page
 */

require_once __DIR__ . '/../app/functions/security_init.php';
require_once __DIR__ . '/../app/functions/auth.php';

$basePath = getBasePath();

// Redirect if already logged in
if (isUserLoggedIn()) {
    header('Location: ' . $basePath . '/');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Forgot Password - JustKleek</title>
    <link href="https://fonts.bunny.net/css2?family=Inter:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">
    <style>
        /* Reusing styles from login.php for consistency */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 50%, #f093fb 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }

        .auth-box {
            background: rgba(255, 255, 255, 0.98);
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            padding: 48px 40px;
            width: 100%;
            max-width: 440px;
        }

        .auth-title {
            font-size: 28px;
            font-weight: 800;
            color: #1a202c;
            margin-bottom: 8px;
            text-align: center;
        }

        .auth-subtitle {
            color: #718096;
            text-align: center;
            margin-bottom: 32px;
            font-size: 15px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            display: block;
            margin-bottom: 10px;
            color: #1a202c;
            font-weight: 600;
            font-size: 14px;
        }

        .form-input {
            width: 100%;
            padding: 16px 20px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 16px;
            font-family: inherit;
        }

        .btn-primary {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, #667eea 0%, #5568d3 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            text-transform: uppercase;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .alert {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-size: 14px;
            display: none;
        }

        .alert-error {
            background: #fff5f5;
            color: #e53e3e;
            border: 1px solid #e53e3e;
        }

        .alert-success {
            background: #f0fff4;
            color: #38a169;
            border: 1px solid #38a169;
        }

        .step-section {
            display: none;
        }

        .step-section.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 24px;
            color: #718096;
            text-decoration: none;
            font-size: 14px;
        }

        .back-link:hover {
            color: #667eea;
        }
    </style>
    <?php require_once __DIR__ . '/../includes/meta_pixel.php'; ?>
</head>

<body>
    <div class="auth-box">
        <h1 class="auth-title">Reset Password</h1>

        <div id="alertBox" class="alert"></div>

        <!-- Step 1: Email -->
        <div id="step1" class="step-section active">
            <p class="auth-subtitle">Enter your email address to receive an OTP.</p>
            <form id="emailForm">
                <div class="form-group">
                    <label class="form-label" for="email">Email Address</label>
                    <input type="email" id="email" class="form-input" placeholder="Enter your email" required autofocus>
                </div>
                <button type="submit" class="btn-primary" id="sendOtpBtn">Send OTP</button>
            </form>
        </div>

        <!-- Step 2: OTP -->
        <div id="step2" class="step-section">
            <p class="auth-subtitle">Enter the 6-digit OTP sent to <span id="displayEmail"
                    style="font-weight:bold;"></span></p>
            <form id="otpForm">
                <div class="form-group">
                    <label class="form-label" for="otp">One-Time Password (OTP)</label>
                    <input type="text" id="otp" class="form-input" placeholder="Enter 6-digit code" maxlength="6"
                        pattern="\d{6}" required>
                </div>
                <button type="submit" class="btn-primary" id="verifyOtpBtn">Verify OTP</button>
                <div style="text-align: center; margin-top: 15px;">
                    <a href="#" id="resendLink" style="color: #667eea; font-size: 13px;">Resend OTP</a>
                </div>
            </form>
        </div>

        <!-- Step 3: New Password -->
        <div id="step3" class="step-section">
            <p class="auth-subtitle">Create a new strong password.</p>
            <form id="passwordForm">
                <div class="form-group">
                    <label class="form-label" for="newPassword">New Password</label>
                    <input type="password" id="newPassword" class="form-input" placeholder="At least 8 characters"
                        required minlength="8">
                </div>
                <div class="form-group">
                    <label class="form-label" for="confirmPassword">Confirm Password</label>
                    <input type="password" id="confirmPassword" class="form-input" placeholder="Re-enter password"
                        required minlength="8">
                </div>
                <button type="submit" class="btn-primary" id="resetBtn">Change Password</button>
            </form>
        </div>

        <!-- Step 4: Success -->
        <div id="step4" class="step-section" style="text-align: center;">
            <div style="font-size: 64px; margin-bottom: 20px;">🎉</div>
            <h2 style="font-size: 24px; color: #1a202c; margin-bottom: 10px;">Password Changed!</h2>
            <p class="auth-subtitle">Your password has been successfully updated.</p>
            <a href="<?php echo $basePath; ?>/auth/login.php" class="btn-primary"
                style="display: inline-block; text-decoration: none; max-width: 200px;">Log In Now</a>
        </div>

        <a href="<?php echo $basePath; ?>/auth/login.php" class="back-link">Back to Login</a>
    </div>

    <script>
        const basePath = '<?php echo $basePath; ?>';
        let userEmail = '';

        function showAlert(message, type) {
            const alertBox = document.getElementById('alertBox');
            alertBox.textContent = message;
            alertBox.className = 'alert alert-' + type;
            alertBox.style.display = 'block';
        }

        function hideAlert() {
            document.getElementById('alertBox').style.display = 'none';
        }

        function switchStep(stepId) {
            document.querySelectorAll('.step-section').forEach(el => el.classList.remove('active'));
            document.getElementById(stepId).classList.add('active');
            hideAlert();
        }

        // Step 1: Send OTP
        document.getElementById('emailForm').addEventListener('submit', async function (e) {
            e.preventDefault();
            const btn = document.getElementById('sendOtpBtn');
            const email = document.getElementById('email').value;

            btn.textContent = 'Sending...';
            btn.disabled = true;
            hideAlert();

            try {
                const formData = new FormData();
                formData.append('action', 'send_otp');
                formData.append('email', email);

                const response = await fetch(basePath + '/app/handlers/forgot_password.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    userEmail = email;
                    document.getElementById('displayEmail').textContent = email;
                    switchStep('step2');
                } else {
                    showAlert(result.error || 'Failed to send OTP', 'error');
                }
            } catch (err) {
                showAlert('An error occurred. Please try again.', 'error');
            } finally {
                btn.textContent = 'Send OTP';
                btn.disabled = false;
            }
        });

        // Step 2: Verify OTP
        document.getElementById('otpForm').addEventListener('submit', async function (e) {
            e.preventDefault();
            const btn = document.getElementById('verifyOtpBtn');
            const otp = document.getElementById('otp').value;

            btn.textContent = 'Verifying...';
            btn.disabled = true;
            hideAlert();

            try {
                const formData = new FormData();
                formData.append('action', 'verify_otp');
                formData.append('email', userEmail);
                formData.append('otp', otp);

                const response = await fetch(basePath + '/app/handlers/forgot_password.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    switchStep('step3');
                } else {
                    showAlert(result.error || 'Invalid OTP', 'error');
                }
            } catch (err) {
                showAlert('An error occurred.', 'error');
            } finally {
                btn.textContent = 'Verify OTP';
                btn.disabled = false;
            }
        });

        // Step 3: Reset Password
        document.getElementById('passwordForm').addEventListener('submit', async function (e) {
            e.preventDefault();
            const btn = document.getElementById('resetBtn');
            const p1 = document.getElementById('newPassword').value;
            const p2 = document.getElementById('confirmPassword').value;

            if (p1 !== p2) {
                showAlert('Passwords do not match', 'error');
                return;
            }

            btn.textContent = 'Updating...';
            btn.disabled = true;
            hideAlert();

            try {
                const formData = new FormData();
                formData.append('action', 'reset_password');
                formData.append('password', p1);
                formData.append('confirm_password', p2);

                const response = await fetch(basePath + '/app/handlers/forgot_password.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    switchStep('step4');
                    document.querySelector('.back-link').style.display = 'none'; // Hide back link
                    document.querySelector('.auth-title').style.display = 'none'; // Hide title
                } else {
                    showAlert(result.error || 'Failed to update password', 'error');
                }
            } catch (err) {
                showAlert('An error occurred.', 'error');
            } finally {
                btn.textContent = 'Change Password';
                btn.disabled = false;
            }
        });

        // Resend Link
        document.getElementById('resendLink').addEventListener('click', async function (e) {
            e.preventDefault();
            if (!userEmail) return;

            const link = this;
            link.textContent = 'Sending...';
            link.style.pointerEvents = 'none';

            try {
                const formData = new FormData();
                formData.append('action', 'send_otp');
                formData.append('email', userEmail);

                const response = await fetch(basePath + '/app/handlers/forgot_password.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();

                if (result.success) {
                    showAlert('New OTP sent!', 'success');
                } else {
                    showAlert(result.error, 'error');
                }
            } catch (err) {
                showAlert('Failed to resend.', 'error');
            } finally {
                setTimeout(() => {
                    link.textContent = 'Resend OTP';
                    link.style.pointerEvents = 'auto';
                }, 2000); // Small cooldown
            }
        });
    </script>
</body>

</html>
