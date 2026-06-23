</div>
</main>
</div>

<!-- Modern Confirmation Modal (if not already present) -->
<?php if (!isset($confirmationModalAdded)): ?>
    <div class="confirmation-modal-overlay" id="confirmationModal"
        style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); z-index: 10000; align-items: center; justify-content: center;">
        <div class="confirmation-modal"
            style="background: white; border-radius: 16px; box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3); max-width: 450px; width: 90%; padding: 0; overflow: hidden;">
            <div class="confirmation-modal-header" style="padding: 24px 24px 16px; border-bottom: 1px solid #e2e8f0;">
                <h3 class="confirmation-modal-title" id="modalTitle"
                    style="font-size: 20px; font-weight: 700; color: #1a202c; margin: 0; display: flex; align-items: center; gap: 12px;">
                    <svg class="confirmation-modal-icon" style="width: 24px; height: 24px; flex-shrink: 0;"
                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <span id="modalTitleText">Confirm Action</span>
                </h3>
            </div>
            <div class="confirmation-modal-body" style="padding: 20px 24px;">
                <p class="confirmation-modal-message" id="modalMessage"
                    style="font-size: 15px; color: #4a5568; line-height: 1.6; margin: 0;">Are you sure you want to proceed?
                </p>
            </div>
            <div class="confirmation-modal-footer"
                style="padding: 16px 24px 24px; display: flex; gap: 12px; justify-content: flex-end;">
                <button class="confirmation-modal-btn confirmation-modal-btn-cancel" id="modalCancelBtn"
                    style="padding: 10px 24px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; background: #f7fafc; color: #4a5568; border: 1px solid #e2e8f0;">No</button>
                <button class="confirmation-modal-btn" id="modalConfirmBtn"
                    style="padding: 10px 24px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">Yes</button>
            </div>
        </div>
    </div>
    <?php $confirmationModalAdded = true; endif; ?>

<style>
    .confirmation-modal-overlay {
        animation: fadeIn 0.2s ease;
    }

    .confirmation-modal-overlay.active {
        display: flex !important;
    }

    .confirmation-modal {
        animation: slideUp 0.3s ease;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
        }

        to {
            opacity: 1;
        }
    }

    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(20px) scale(0.95);
        }

        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .confirmation-modal-btn {
        transition: all 0.2s ease;
        font-family: inherit;
    }

    .confirmation-modal-btn-cancel:hover {
        background: #edf2f7 !important;
        border-color: #cbd5e0 !important;
    }

    .confirmation-modal-btn-confirm {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%) !important;
    }

    .confirmation-modal-btn-confirm:hover {
        background: linear-gradient(135deg, #5568d3 0%, #6a3d8f 100%) !important;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
    }

    .confirmation-modal-btn-delete {
        background: linear-gradient(135deg, #dc3545 0%, #c82333 100%) !important;
    }

    .confirmation-modal-btn-delete:hover {
        background: linear-gradient(135deg, #c82333 0%, #bd2130 100%) !important;
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(220, 53, 69, 0.4);
    }

    /* Google Identity (M3) PIN Modal Styles - Ultra Polished */
    .pin-modal-overlay {
        animation: googleFadeIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        font-family: 'Roboto', 'Inter', 'Google Sans', sans-serif;
        background: rgba(255, 255, 255, 0.94) !important;
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }

    .pin-modal-overlay.active {
        display: flex !important;
    }

    .pin-modal {
        animation: googleSlideUp 0.4s cubic-bezier(0, 0, 0.2, 1);
        width: 448px !important;
        padding: 48px 40px 36px !important;
        border: 1px solid #dadce0 !important;
        border-radius: 8px !important;
        background: #fff !important;
        position: relative;
        box-shadow: 0 1px 2px 0 rgba(60,64,67,0.3), 0 1px 3px 1px rgba(60,64,67,0.15) !important;
    }

    /* Google-style Linear Progress Bar */
    .pin-progress-container {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        overflow: hidden;
        border-top-left-radius: 8px;
        border-top-right-radius: 8px;
        display: none;
    }

    .pin-progress-bar {
        height: 100%;
        background-color: #1a73e8;
        width: 100%;
        animation: googleLoading 1s infinite linear;
        transform-origin: 0% 50%;
    }

    @keyframes googleLoading {
        0% { transform: translateX(-100%); }
        100% { transform: translateX(100%); }
    }

    @media (max-width: 450px) {
        .pin-modal {
            width: 100% !important;
            height: 100% !important;
            border-radius: 0 !important;
            padding: 80px 24px 24px !important;
            border: none !important;
            box-shadow: none !important;
        }
        .pin-progress-container { border-radius: 0; }
    }

    .pin-input-container {
        position: relative;
        margin-bottom: 32px;
    }

    .pin-input {
        width: 100% !important;
        height: 56px !important;
        padding: 13px 15px !important;
        border: 1px solid #dadce0 !important;
        border-radius: 4px !important;
        font-size: 16px !important;
        letter-spacing: 4px !important;
        text-align: left !important;
        color: #202124 !important;
        transition: border-color 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
        outline: none !important;
        box-sizing: border-box !important;
    }

    .pin-input:focus {
        border: 2px solid #1a73e8 !important;
        padding: 12px 14px !important;
    }

    .pin-error {
        color: #d93025;
        font-size: 12px;
        margin-top: 8px;
        display: none;
        align-items: center;
        gap: 8px;
        font-weight: 500;
    }

    .google-btn {
        height: 36px;
        padding: 0 24px;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 500;
        letter-spacing: .25px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: background-color .2s, box-shadow .2s;
        border: none;
        font-family: inherit;
    }

    .google-btn-primary {
        background-color: #1a73e8;
        color: #fff;
    }

    .google-btn-primary:hover {
        background-color: #185abc;
        box-shadow: 0 1px 2px 0 rgba(60,64,67,0.3), 0 1px 3px 1px rgba(60,64,67,0.15);
    }

    .google-btn-text {
        background-color: transparent;
        color: #1a73e8;
    }

    .google-btn-text:hover {
        background-color: #f6f9fe;
        color: #174ea6;
    }

    @keyframes googleFadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    @keyframes googleSlideUp {
        from { transform: scale(0.9); opacity: 0; }
        to { transform: scale(1); opacity: 1; }
    }
</style>

<!-- Google Style Admin PIN Verification Modal (Material 3) -->
<div class="pin-modal-overlay" id="adminPinModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 10000; align-items: center; justify-content: center;">
    <div class="pin-modal">
        <!-- Google Progress Bar -->
        <div class="pin-progress-container" id="pinLoader">
            <div class="pin-progress-bar"></div>
        </div>

        <div style="text-align: center; margin-bottom: 12px;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="#4285f4">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"></path>
            </svg>
        </div>
        
        <h1 style="font-size: 24px; font-weight: 400; color: #202124; margin: 0 0 8px; text-align: center;">🛡️ Verify it's you</h1>
        
        <div style="display: flex; align-items: center; justify-content: center; gap: 8px; margin-bottom: 40px; border: 1px solid #dadce0; padding: 6px 12px; border-radius: 20px; width: fit-content; margin-left: auto; margin-right: auto; background: #fff;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#5f6368" stroke-width="2">
                <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path>
                <circle cx="12" cy="7" r="4"></circle>
            </svg>
            <span style="font-size: 14px; font-weight: 500; color: #3c4043;"><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></span>
        </div>
        
        <div id="pinEntryView">
            <div class="pin-input-container">
                <p style="font-size: 14px; color: #3c4043; margin-bottom: 8px; font-weight: 400;">Enter your 4-digit admin PIN</p>
                <div style="position: relative;">
                    <input type="password" id="adminPinInput" class="pin-input" maxlength="4" placeholder="••••" autocomplete="off" inputmode="numeric">
                    <button type="button" id="toggleAdminPin" style="position: absolute; right: 12px; top: 16px; background: none; border: none; padding: 0; color: #5f6368; cursor: pointer; display: flex; align-items: center; justify-content: center;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="eye-icon"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </button>
                </div>
                <div id="adminPinError" class="pin-error">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"></path></svg>
                    Wrong PIN. Try again.
                </div>
            </div>

            <div style="margin-bottom: 32px;">
                <button id="forgotPinBtn" style="background:none; border:none; color:#1a73e8; font-size:14px; font-weight:500; cursor:pointer; padding:0; font-family:inherit;">Forgot PIN?</button>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center;">
                <button id="cancelPinBtn" class="google-btn google-btn-text">Cancel</button>
                <button id="verifyPinBtn" class="google-btn google-btn-primary">Verify</button>
            </div>
        </div>

        <!-- Reset View (Hidden by default) -->
        <div id="pinResetView" style="display: none;">
            <p style="font-size: 14px; color: #3c4043; margin-bottom: 24px; line-height: 1.4; text-align: center;">A 6-digit verification code has been sent to your registered admin email. Enter it below with your new 4-digit PIN.</p>
            
            <div style="margin-bottom: 20px; text-align: center;">
                <label style="display:block; font-size:12px; color:#5f6368; margin-bottom:4px; font-weight:500; text-align: center;">Verification Code (OTP)</label>
                <input type="text" id="resetOtp" class="pin-input" maxlength="6" placeholder="000000" style="letter-spacing: 2px; text-align: center;">
            </div>

            <div style="margin-bottom: 32px; text-align: center;">
                <label style="display:block; font-size:12px; color:#5f6368; margin-bottom:4px; font-weight:500; text-align: center;">New 4-Digit PIN</label>
                <div style="position: relative;">
                    <input type="password" id="newPinInput" class="pin-input" maxlength="4" placeholder="••••" style="letter-spacing: 2px; text-align: center;">
                    <button type="button" id="toggleNewPin" style="position: absolute; right: 12px; top: 16px; background: none; border: none; padding: 0; color: #5f6368; cursor: pointer; display: flex; align-items: center; justify-content: center;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    </button>
                </div>
            </div>

            <div id="resetError" class="pin-error" style="margin-bottom: 20px;"></div>

            <div style="display: flex; justify-content: space-between; align-items: center;">
                <button id="backToPinBtn" class="google-btn google-btn-text">Back</button>
                <button id="confirmResetBtn" class="google-btn google-btn-primary" style="background-color: #d93025;">Reset PIN</button>
            </div>
        </div>
    </div>
</div>

<script>
    // Modern Confirmation Modal Handler (if not already defined)
    if (typeof window.showConfirmationModal === 'undefined') {
        window.showConfirmationModal = function (options) {
            return new Promise((resolve) => {
                const modal = document.getElementById('confirmationModal');
                if (!modal) {
                    resolve(false);
                    return;
                }

                const titleText = document.getElementById('modalTitleText');
                const message = document.getElementById('modalMessage');
                const confirmBtn = document.getElementById('modalConfirmBtn');
                const cancelBtn = document.getElementById('modalCancelBtn');

                // Set modal content
                if (titleText) titleText.textContent = options.title || 'Confirm Action';
                if (message) message.textContent = options.message || 'Are you sure you want to proceed?';

                // Set button styles based on type
                if (confirmBtn) {
                    confirmBtn.className = 'confirmation-modal-btn';
                    if (options.type === 'delete') {
                        confirmBtn.classList.add('confirmation-modal-btn-delete');
                    } else {
                        confirmBtn.classList.add('confirmation-modal-btn-confirm');
                    }
                    confirmBtn.textContent = options.confirmText || 'Yes';
                }

                // Remove previous event listeners by cloning
                const newConfirmBtn = confirmBtn ? confirmBtn.cloneNode(true) : null;
                const newCancelBtn = cancelBtn ? cancelBtn.cloneNode(true) : null;
                if (confirmBtn && newConfirmBtn) confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
                if (cancelBtn && newCancelBtn) cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);

                // Add new event listeners
                if (newConfirmBtn) {
                    newConfirmBtn.addEventListener('click', () => {
                        modal.classList.remove('active');
                        document.documentElement.classList.remove('modal-open');
                        document.body.classList.remove('modal-open');
                        resolve(true);
                    });
                }

                if (newCancelBtn) {
                    newCancelBtn.addEventListener('click', () => {
                        modal.classList.remove('active');
                        document.documentElement.classList.remove('modal-open');
                        document.body.classList.remove('modal-open');
                        resolve(false);
                    });
                }

                // Close on overlay click
                const overlayClickHandler = (e) => {
                    if (e.target === modal) {
                        modal.classList.remove('active');
                        document.documentElement.classList.remove('modal-open');
                        document.body.classList.remove('modal-open');
                        modal.removeEventListener('click', overlayClickHandler);
                        resolve(false);
                    }
                };
                modal.addEventListener('click', overlayClickHandler);

                // Show modal
                document.documentElement.classList.add('modal-open');
                document.body.classList.add('modal-open');
                modal.classList.add('active');
            });
        }
    }

    // Logout Confirmation Handler
    document.addEventListener('DOMContentLoaded', function () {
        const logoutBtn = document.getElementById('logoutBtn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function (e) {
                e.preventDefault();
                const logoutUrl = this.getAttribute('href');

                window.showConfirmationModal({
                    title: 'Logout',
                    message: 'Are you sure you want to logout?',
                    type: 'confirm',
                    confirmText: 'Yes, Logout'
                }).then(confirmed => {
                    if (confirmed) {
                        window.location.href = logoutUrl;
                    }
                });
            });
        }

        // Admin PIN Verification Logic
        const pinModal = document.getElementById('adminPinModal');
        const pinInput = document.getElementById('adminPinInput');
        const pinError = document.getElementById('adminPinError');
        const verifyPinBtn = document.getElementById('verifyPinBtn');
        const cancelPinBtn = document.getElementById('cancelPinBtn');
        let pendingUrl = '';
        
        // Let the JS know the server-side status
        let isPinVerified = <?php echo isAdminPinVerified() ? 'true' : 'false'; ?>;

        // --- JK-FIX: PERSIST BRANCH FOR ALL NAVIGATION ---
        document.querySelectorAll('.nav-item').forEach(link => {
            const originalHandler = link.onclick;
            link.addEventListener('click', function(e) {
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.has('branch')) {
                    const branchVal = urlParams.get('branch');
                    let destUrl = this.getAttribute('href');
                    
                    if (destUrl && destUrl !== '#' && !destUrl.includes('logout.php')) {
                        const separator = destUrl.includes('?') ? '&' : '?';
                        if (!destUrl.includes('branch=')) {
                            e.preventDefault();
                            const finalUrl = destUrl + separator + 'branch=' + encodeURIComponent(branchVal);
                            
                            // If it's a requires-pin link and pin not verified, handled by the other listener
                            if (this.classList.contains('requires-pin') && !isPinVerified) {
                                return; // Let the requires-pin listener handle it
                            }
                            window.location.href = finalUrl;
                        }
                    }
                }
            });
        });

        // Attach to all elements with requires-pin class
        document.querySelectorAll('.requires-pin').forEach(link => {
            link.addEventListener('click', async function(e) {
                // If verified, proceed immediately
                if (isPinVerified) {
                    // Even if verified, we might need to append the branch if the above listener didn't catch it
                    const urlParams = new URLSearchParams(window.location.search);
                    if (urlParams.has('branch')) {
                        let destUrl = this.getAttribute('href');
                        if (!destUrl.includes('branch=')) {
                            e.preventDefault();
                            const separator = destUrl.includes('?') ? '&' : '?';
                            window.location.href = destUrl + separator + 'branch=' + encodeURIComponent(urlParams.get('branch'));
                        }
                    }
                    return;
                }

                e.preventDefault();
                let destUrl = this.getAttribute('href');
                
                // --- JK-FIX: PERSIST BRANCH FOR SUPER ADMIN ---
                const urlParams = new URLSearchParams(window.location.search);
                if (urlParams.has('branch')) {
                    const branchVal = urlParams.get('branch');
                    const separator = destUrl.includes('?') ? '&' : '?';
                    if (!destUrl.includes('branch=')) {
                        destUrl += separator + 'branch=' + encodeURIComponent(branchVal);
                    }
                }
                pendingUrl = destUrl;
                
                // Show modal
                pinInput.value = '';
                pinError.style.display = 'none';
                document.documentElement.classList.add('modal-open');
                document.body.classList.add('modal-open');
                pinModal.classList.add('active');
                
                // Focus input quickly after modal animation starts
                setTimeout(() => pinInput.focus(), 50);
            });
        });

        // AUTO-PROMPT: If we were redirected here because a PIN was required
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('error') === 'pin_required' && !isPinVerified) {
            document.documentElement.classList.add('modal-open');
            document.body.classList.add('modal-open');
            pinModal.classList.add('active');
            setTimeout(() => pinInput.focus(), 50);
        }

        // Verify PIN Function via Server Call
        async function attemptPinVerification() {
            const inputVal = pinInput.value;
            if (!inputVal) return;

            // Show loading state
            verifyPinBtn.disabled = true;
            document.getElementById('pinLoader').style.display = 'block';

            try {
                const response = await fetch('api/verify_pin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ pin: inputVal })
                });
                const result = await response.json();

                if (result.success) {
                    isPinVerified = true;
                    document.documentElement.classList.remove('modal-open');
                    document.body.classList.remove('modal-open');
                    pinModal.classList.remove('active');
                    if (pendingUrl) {
                        window.location.href = pendingUrl;
                    }
                } else {
                    pinError.style.display = 'flex';
                    // Show dynamic message from server (e.g. Remaining attempts: 6)
                    pinError.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"></path></svg> ' + (result.message || 'Wrong PIN. Try again.');
                    
                    pinInput.value = '';
                    pinInput.focus();
                    
                    // Simple shake for error
                    pinModal.animate([
                        { transform: 'translateX(-4px)' },
                        { transform: 'translateX(4px)' },
                        { transform: 'translateX(-4px)' },
                        { transform: 'translateX(0px)' }
                    ], { duration: 250 });
                }
            } catch (err) {
                console.error('PIN Verification Error:', err);
                pinError.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"></path></svg> Connection error.';
                pinError.style.display = 'flex';
            } finally {
                verifyPinBtn.disabled = false;
                document.getElementById('pinLoader').style.display = 'none';
            }
        }

        if (verifyPinBtn) {
            verifyPinBtn.addEventListener('click', attemptPinVerification);
        }

        if (pinInput) {
            pinInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    attemptPinVerification();
                }
            });
            // Only allow numbers
            pinInput.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
        }

        if (cancelPinBtn) {
            cancelPinBtn.addEventListener('click', () => {
                document.documentElement.classList.remove('modal-open');
                document.body.classList.remove('modal-open');
                pinModal.classList.remove('active');
                pendingUrl = '';
            });
            
            // Close on overlay click
            pinModal.addEventListener('click', (e) => {
                if (e.target === pinModal) {
                    document.documentElement.classList.remove('modal-open');
                    document.body.classList.remove('modal-open');
                    pinModal.classList.remove('active');
                    pendingUrl = '';
                }
            });
        }

        // Toggle PIN visibility
        const togglePinBtn = document.getElementById('toggleAdminPin');
        if (togglePinBtn && pinInput) {
            togglePinBtn.addEventListener('click', function() {
                const type = pinInput.getAttribute('type') === 'password' ? 'text' : 'password';
                pinInput.setAttribute('type', type);
                
                if (type === 'text') {
                    this.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24M1 1l22 22"></path></svg>';
                } else {
                    this.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
                }
            });
        }

        // Forgot PIN / Reset Flow Logic
        const entryView = document.getElementById('pinEntryView');
        const resetView = document.getElementById('pinResetView');
        const forgotPinBtn = document.getElementById('forgotPinBtn');
        const backToPinBtn = document.getElementById('backToPinBtn');
        const confirmResetBtn = document.getElementById('confirmResetBtn');
        const pinLoader = document.getElementById('pinLoader');

        if (forgotPinBtn) {
            forgotPinBtn.addEventListener('click', async () => {
                pinLoader.style.display = 'block';
                try {
                    const res = await fetch('api/send_pin_otp.php');
                    const data = await res.json();
                    if (data.success) {
                        entryView.style.display = 'none';
                        resetView.style.display = 'block';
                    } else {
                        alert(data.message || 'Error sending OTP');
                    }
                } catch (e) {
                    alert('Connection error');
                } finally {
                    pinLoader.style.display = 'none';
                }
            });
        }

        if (backToPinBtn) {
            backToPinBtn.addEventListener('click', () => {
                resetView.style.display = 'none';
                entryView.style.display = 'block';
            });
        }

        if (confirmResetBtn) {
            confirmResetBtn.addEventListener('click', async () => {
                const otp = document.getElementById('resetOtp').value;
                const newPin = document.getElementById('newPinInput').value;
                const resetError = document.getElementById('resetError');

                if (!otp || !newPin) return;

                pinLoader.style.display = 'block';
                try {
                    const res = await fetch('api/reset_pin_with_otp.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ otp, newPin })
                    });
                    const data = await res.json();
                    if (data.success) {
                        isPinVerified = true;
                        document.documentElement.classList.remove('modal-open');
                        document.body.classList.remove('modal-open');
                        pinModal.classList.remove('active');
                        if (pendingUrl) window.location.href = pendingUrl;
                    } else {
                        resetError.style.display = 'flex';
                        resetError.textContent = data.message;
                    }
                } catch (e) {
                    resetError.style.display = 'flex';
                    resetError.textContent = 'Connection error';
                } finally {
                    pinLoader.style.display = 'none';
                }
            });
        }

        // Toggle New PIN visibility
        const toggleNewPinBtn = document.getElementById('toggleNewPin');
        const newPinInput = document.getElementById('newPinInput');
        if (toggleNewPinBtn && newPinInput) {
            toggleNewPinBtn.addEventListener('click', function() {
                const type = newPinInput.getAttribute('type') === 'password' ? 'text' : 'password';
                newPinInput.setAttribute('type', type);
                
                if (type === 'text') {
                    this.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24M1 1l22 22"></path></svg>';
                } else {
                    this.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
                }
            });
        }
    });
</script>

<script>
    // Update current time (Nepal timezone)
    function updateTime() {
        const now = new Date();
        const nepaliTime = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Kathmandu' }));
        const timeStr = nepaliTime.toLocaleTimeString('en-US', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: true
        });
        const timeEl = document.getElementById('currentTime');
        if (timeEl) {
            timeEl.textContent = timeStr;
        }
    }
    updateTime();
    setInterval(updateTime, 1000);

    // Sidebar toggle for mobile
    (function initSidebarToggle() {
        function setupSidebarToggle() {
            const sidebarToggle = document.getElementById('sidebarToggle');
            const adminWrapper = document.querySelector('.admin-wrapper');
            const adminSidebar = document.querySelector('.admin-sidebar');
            if (!adminWrapper) return;

            function toggleSidebar() {
                adminWrapper.classList.toggle('sidebar-open');
                document.body.classList.toggle('sidebar-open');
            }
            function closeSidebar() {
                adminWrapper.classList.remove('sidebar-open');
                document.body.classList.remove('sidebar-open');
            }

            if (sidebarToggle) {
                const newToggle = sidebarToggle.cloneNode(true);
                sidebarToggle.parentNode.replaceChild(newToggle, sidebarToggle);
                newToggle.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    toggleSidebar();
                });
            }

            document.addEventListener('click', (e) => {
                if (window.innerWidth <= 768 && adminWrapper.classList.contains('sidebar-open')) {
                    if (!e.target.closest('.admin-sidebar') && !e.target.closest('.sidebar-toggle')) {
                        closeSidebar();
                    }
                }
            });

            if (adminSidebar) {
                adminSidebar.querySelectorAll('.nav-item').forEach(item => {
                    item.addEventListener('click', () => {
                        if (window.innerWidth <= 768) closeSidebar();
                    });
                });
            }
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setupSidebarToggle);
        else setupSidebarToggle();
    })();





    // Auto-refresh new orders count and play sound if increased
    let isInitialCountCheck = true;
    let isFetchingStats = false;

    function updateNewOrdersCount() {
        if (isFetchingStats) return; // Prevent overlapping polls if server is slow
        
        isFetchingStats = true;
        const isDashboard = window.location.pathname.includes('admin_dashboard.php');
        
        const urlParams = new URLSearchParams(window.location.search);
        let fetchUrl = 'api/get_stats.php';
        if (urlParams.has('branch')) {
            fetchUrl += '?branch=' + encodeURIComponent(urlParams.get('branch'));
        }
        
        fetch(fetchUrl, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(response => {
                if (response.status === 401) {
                    window.location.reload();
                    return Promise.reject('Unauthorized');
                }
                return response.json();
            })
            .then(data => {
                isFetchingStats = false;
                if (data.success && data.new_orders_count !== undefined) {
                    const serverCount = parseInt(data.new_orders_count);
                    const serverLatestId = parseInt(data.latest_id || 0);
                    
                    const storedLatestId = parseInt(localStorage.getItem('jk_lastLatestId_Global') || '0', 10);
                    const skipNotif = localStorage.getItem('jk_skipNextNotification') === 'true';

                    // ACCURATE TRIGGER: Play sound ONLY for a brand-new ID detected AFTER the initial page load poll
                    // This is the absolute guarantee that refresh will NEVER trigger a sound for an old unread order.
                    if (!isInitialCountCheck && serverLatestId > 0 && serverLatestId > storedLatestId && !skipNotif) {
                        // Sync dashboard view if on that page
                        if (isDashboard && typeof window.refreshDashboardOrderList === 'function') {
                            window.refreshDashboardOrderList();
                        }
                    }
                    
                    // Consolidate: If on dashboard, tell it to update its UI with this fresh data
                    if (isDashboard && typeof window.refreshDashboardUI === 'function') {
                        window.refreshDashboardUI(data);
                    }
                    
                    // Always clear the skip flag AFTER consumers check it
                    if (skipNotif) localStorage.removeItem('jk_skipNextNotification');

                    // Always store the current state to prevent repeat sounds
                    localStorage.setItem('jk_lastLatestId_Global', serverLatestId);
                    localStorage.setItem('jk_lastNewOrderCount_Global', serverCount);
                    
                    // Mark initial session check as complete
                    isInitialCountCheck = false;
                }
            })
            .catch(err => {
                isFetchingStats = false;
                console.error('Error updating stats:', err);
            });
    }

    // Start polling loop
    updateNewOrdersCount(); // Initial silent poll on load
    setInterval(updateNewOrdersCount, 3000); // Subsequent active polls
</script>



<!-- Receipt Print Modal -->
<div id="receiptPrintModal" class="receipt-modal-overlay" style="display: none;">
    <div class="receipt-modal-container">
        <div class="receipt-modal-header">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="background: #eef2ff; padding: 8px; border-radius: 8px; color: #4338ca;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M6 9V2h12v7"></path>
                        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                        <path d="M6 14h12v8H6z"></path>
                    </svg>
                </div>
                <h3 id="receiptModalTitle" style="margin: 0; font-size: 16px; font-weight: 800; color: #111; letter-spacing: -0.01em;">Print Receipt</h3>
            </div>
            <button class="receipt-modal-close" onclick="closePrintModal()">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M18 6L6 18M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        <div class="receipt-modal-body">
            <iframe id="receiptFrame" src="about:blank"></iframe>
        </div>
    </div>
</div>

<style>
.receipt-modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(8px);
    z-index: 100000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    animation: receiptFadeIn 0.2s ease-out;
}
.receipt-modal-container {
    background: #fff;
    width: 100%;
    max-width: 480px;
    height: 85vh;
    max-height: 750px;
    border-radius: 20px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    animation: receiptModalPop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
}
@keyframes receiptFadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
@keyframes receiptModalPop {
    from { transform: scale(0.9) translateY(20px); opacity: 0; }
    to { transform: scale(1) translateY(0); opacity: 1; }
}
.receipt-modal-header {
    padding: 16px 24px;
    background: #fff;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.receipt-modal-close {
    background: #f1f5f9;
    border: none;
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #64748b;
    transition: all 0.2s;
}
.receipt-modal-close:hover {
    background: #fee2e2;
    color: #ef4444;
    transform: rotate(90deg);
}
.receipt-modal-body { 
    flex: 1; 
    background: #f8fafc; 
    position: relative; 
}
#receiptFrame { 
    width: 100%; 
    height: 100%; 
    border: none; 
}

/* Scrollbar for modal content if needed */
.receipt-modal-body::-webkit-scrollbar {
    width: 6px;
}
.receipt-modal-body::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 10px;
}
</style>

<script>
function openPrintModal(url, title) {
    const modal = document.getElementById('receiptPrintModal');
    const frame = document.getElementById('receiptFrame');
    const modalTitle = document.getElementById('receiptModalTitle');
    
    modalTitle.textContent = title || 'Print Receipt';
    frame.src = url;
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closePrintModal() {
    const modal = document.getElementById('receiptPrintModal');
    const frame = document.getElementById('receiptFrame');
    
    modal.style.display = 'none';
    frame.src = 'about:blank';
    document.body.style.overflow = '';
}

// Intercept print button clicks globally
document.addEventListener('click', function(e) {
    const printBtn = e.target.closest('.print-receipt-btn');
    if (printBtn) {
        e.preventDefault();
        const url = printBtn.getAttribute('href');
        const title = printBtn.getAttribute('title') || 'Print Receipt';
        openPrintModal(url, title);
    }
});

// Close on escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closePrintModal();
    }
});

// Close on backdrop click
document.getElementById('receiptPrintModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closePrintModal();
    }
});
</script>

<?php
// Include custom scripts (like updatePayment)
if (file_exists(__DIR__ . '/footer_scripts.php')) {
    include __DIR__ . '/footer_scripts.php';
}
?>
</body>

</html>