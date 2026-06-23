<?php
/**
 * Email Verification Modal
 * 
 * Included on pages that require verified email access.
 */
?>
<div id="emailVerificationModal" class="verification-modal-overlay">
    <div class="verification-modal-content">
        <button class="verification-modal-close" onclick="handleVerificationLater()">&times;</button>

        <div class="verification-icon-container">
            <div class="verification-icon-dashed-circle">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <!-- Minute Hand -->
                    <path class="clock-hand-minute" d="M12 12L12 6" stroke="#E31837" stroke-width="2"
                        stroke-linecap="round" />
                    <!-- Hour Hand -->
                    <path class="clock-hand-hour" d="M12 12L16 14" stroke="#E31837" stroke-width="2"
                        stroke-linecap="round" />
                </svg>
            </div>
        </div>

        <h2 class="verification-modal-title">Verify Your Email</h2>
        <p class="verification-modal-text">
            Please verify your email before placing orders.
        </p>

        <div class="verification-modal-actions">
            <a href="<?php echo getBasePath(); ?>/profile?action=verify" class="verification-btn-primary">
                <div class="btn-icon-circle">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2.5">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                </div>
                Verify Now
            </a>
            <button onclick="handleVerificationLater()" class="verification-btn-secondary">
                <div class="btn-icon-circle secondary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2.5">
                        <circle cx="12" cy="12" r="10"></circle>
                        <path d="M12 8v4"></path>
                        <path d="M12 16h.01"></path>
                    </svg>
                </div>
                Maybe Later
            </button>
        </div>

        <p class="verification-modal-footer">
            You'll be redirected to verify from your profile.
        </p>
    </div>
</div>

<style>
    .verification-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(4px);
        z-index: 9999999;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }

    .verification-modal-overlay.active {
        opacity: 1;
        visibility: visible;
    }

    .verification-modal-content {
        background: white;
        width: 90%;
        max-width: 400px;
        border-radius: 20px;
        padding: 40px 30px;
        text-align: center;
        position: relative;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        transform: scale(0.9);
        transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    .verification-modal-overlay.active .verification-modal-content {
        transform: scale(1);
    }

    .verification-modal-close {
        position: absolute;
        top: 15px;
        right: 15px;
        background: #f3f4f6;
        border: none;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        font-size: 20px;
        color: #6b7280;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
    }

    .verification-modal-close:hover {
        background: #e5e7eb;
        color: #1f2937;
    }

    .verification-icon-container {
        display: flex;
        justify-content: center;
        margin-bottom: 24px;
    }

    .verification-icon-dashed-circle {
        width: 80px;
        height: 80px;
        border: 2px dashed rgba(227, 24, 55, 0.2);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
    }

    .verification-icon-dashed-circle::before {
        content: '';
        position: absolute;
        width: 60px;
        height: 60px;
        background: rgba(227, 24, 55, 0.05);
        border-radius: 50%;
        z-index: 0;
    }

    .verification-icon-dashed-circle svg {
        position: relative;
        z-index: 1;
        width: 32px;
        height: 32px;
    }

    .verification-modal-title {
        font-family: 'Montserrat', sans-serif;
        font-size: 24px;
        font-weight: 700;
        color: #1f2937;
        margin: 0 0 12px 0;
    }

    .verification-modal-text {
        font-family: 'Inter', sans-serif;
        font-size: 15px;
        color: #4b5563;
        line-height: 1.5;
        margin: 0 0 30px 0;
    }

    .verification-modal-actions {
        display: flex;
        flex-direction: column;
        gap: 12px;
        margin-bottom: 24px;
    }

    .verification-btn-primary {
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #E31837 0%, #C4122C 100%);
        color: white;
        text-decoration: none;
        padding: 14px;
        border-radius: 12px;
        font-weight: 600;
        font-family: 'Montserrat', sans-serif;
        transition: all 0.2s;
        border: none;
        cursor: pointer;
        font-size: 16px;
        box-shadow: 0 4px 12px rgba(227, 24, 55, 0.3);
    }

    .verification-btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(227, 24, 55, 0.4);
    }

    .verification-btn-secondary {
        display: flex;
        align-items: center;
        justify-content: center;
        background: white;
        color: #E31837;
        border: 2px solid rgba(227, 24, 55, 0.2);
        padding: 14px;
        border-radius: 12px;
        font-weight: 600;
        font-family: 'Montserrat', sans-serif;
        transition: all 0.2s;
        cursor: pointer;
        font-size: 16px;
    }

    .verification-btn-secondary:hover {
        background: #FFF0F2;
        border-color: #E31837;
    }

    .btn-icon-circle {
        width: 24px;
        height: 24px;
        border: 2px solid rgba(255, 255, 255, 0.4);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 10px;
    }

    .btn-icon-circle svg {
        width: 14px;
        height: 14px;
    }

    .btn-icon-circle.secondary {
        border-color: rgba(227, 24, 55, 0.3);
        color: #E31837;
    }

    .verification-modal-footer {
        font-size: 13px;
        color: #9ca3af;
        margin: 0;
    }

    /* Live Clock Animation */
    @keyframes spinCW {
        to {
            transform: rotate(360deg);
        }
    }

    @keyframes spinCCW {
        to {
            transform: rotate(-360deg);
        }
    }

    .verification-icon-dashed-circle {
        animation: spinCW 20s linear infinite;
        /* Rotate the dashed border slowly */
    }

    /* Counter-rotate the inner SVG so the icon stays upright while border spins */
    .verification-icon-dashed-circle svg {
        animation: spinCCW 20s linear infinite;
    }

    .clock-hand-minute {
        transform-origin: 12px 12px;
        animation: spinCW 4s linear infinite;
    }

    .clock-hand-hour {
        transform-origin: 12px 12px;
        animation: spinCW 24s linear infinite;
    }

    /* Verify Button Breathing Animation */
    @keyframes btnBreathing {
        0% {
            transform: scale(1);
            box-shadow: 0 4px 12px rgba(227, 24, 55, 0.3);
        }

        50% {
            transform: scale(1.03);
            box-shadow: 0 8px 20px rgba(227, 24, 55, 0.5);
        }

        100% {
            transform: scale(1);
            box-shadow: 0 4px 12px rgba(227, 24, 55, 0.3);
        }
    }

    .verification-btn-primary {
        animation: btnBreathing 2s infinite ease-in-out;
    }
</style>

<script>
    function showVerificationModal() {
        const modal = document.getElementById('emailVerificationModal');
        if (modal) {
            modal.classList.add('active');
            // Prevent scrolling
            document.documentElement.classList.add('modal-open');
            document.body.classList.add('modal-open');
        }
    }

    function closeVerificationModal() {
        const modal = document.getElementById('emailVerificationModal');
        if (modal) {
            modal.classList.remove('active');
            document.documentElement.classList.remove('modal-open');
            document.body.classList.remove('modal-open');
        }
    }

    function handleVerificationLater() {
        // Redirect to homepage or simply close if we want to allow browsing (but blocking action)
        // Since this modal blocks critical features, we redirect to home
        window.location.href = '<?php echo getBasePath(); ?>/index.php';
    }

    // Auto-show logic is handled by the including page (if variable is set)
</script>
