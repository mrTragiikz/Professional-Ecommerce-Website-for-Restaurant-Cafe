<!-- Logout Confirmation Modal -->
<div id="dvLogoutModal" class="dv-modal-overlay">
    <div class="dv-modal-content">
        <div class="dv-modal-header">
            <h3>Confirm Logout</h3>
        </div>
        <div class="dv-modal-body">
            <p>Are you sure you want to logout?</p>
        </div>
        <div class="dv-modal-footer">
            <button id="dvLogoutCancel" class="dv-modal-btn dv-btn-cancel">Cancel</button>
            <button id="dvLogoutConfirm" class="dv-modal-btn dv-btn-confirm">Logout</button>
        </div>
    </div>
</div>

<style>
    /* Logout Modal Styles */
    /* Logout Modal Styles */
    .dv-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100vh;
        /* changed to vh for better mobile coverage */
        background: rgba(0, 0, 0, 0.5);
        /* Semi-transparent black */
        backdrop-filter: blur(4px);
        /* Blur effect */
        -webkit-backdrop-filter: blur(4px);
        /* Safari support */
        z-index: 999999999;
        /* Max z-index */
        display: none;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity 0.3s ease;
        overscroll-behavior: contain;
        /* Prevent scroll chaining */
        touch-action: none;
        /* Disable touch actions */
    }

    .dv-modal-overlay.show {
        display: flex;
        opacity: 1;
    }

    .dv-modal-content {
        background: white;
        padding: 32px 24px;
        border-radius: 24px;
        /* More rounded modern look */
        width: 85%;
        max-width: 360px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);

        /* Initial State for Animation */
        transform: scale(0.9) translateY(10px);
        opacity: 0;

        transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.3s ease;
        text-align: center;
        border: 1px solid rgba(0, 0, 0, 0.05);
    }

    .dv-modal-overlay.show .dv-modal-content {
        transform: scale(1) translateY(0);
        opacity: 1;
    }

    .dv-modal-header h3 {
        margin: 0 0 12px 0;
        font-size: 18px;
        color: #333;
    }

    .dv-modal-body p {
        margin: 0 0 24px 0;
        font-size: 16px;
        color: #666;
    }

    .dv-modal-footer {
        display: flex;
        gap: 12px;
        justify-content: center;
    }

    .dv-modal-btn {
        padding: 12px 28px;
        border-radius: 12px;
        border: none;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: transform 0.1s ease, background 0.2s ease, box-shadow 0.2s ease;
    }

    .dv-modal-btn:active {
        transform: scale(0.95);
    }

    .dv-btn-cancel {
        background: #f5f5f5;
        color: #333;
    }

    .dv-btn-cancel:hover {
        background: #e0e0e0;
    }

    .dv-btn-confirm {
        background: #ff4757;
        /* Red for logout */
        color: white;
    }

    .dv-btn-confirm:hover {
        background: #e84118;
        box-shadow: 0 4px 12px rgba(255, 71, 87, 0.3);
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const modal = document.getElementById('dvLogoutModal');
        const confirmBtn = document.getElementById('dvLogoutConfirm');
        const cancelBtn = document.getElementById('dvLogoutCancel');
        let logoutTargetUrl = '';

        // Function to prevent touch scroll
        const preventTouch = (e) => {
            if (e.target.closest('.dv-modal-content')) return; // Allow scroll inside modal if needed
            e.preventDefault();
        };

        // Function to open modal
        const openLogoutModal = (e, url) => {
            e.preventDefault();
            logoutTargetUrl = url;
            modal.style.display = 'flex';

            // Stronger Scroll Lock
            document.documentElement.style.overflow = 'hidden';
            document.body.style.overflow = 'hidden';

            // Prevent touch moves on the overlay backlayer
            modal.addEventListener('touchmove', preventTouch, { passive: false });

            // Small delay to allow display:flex to apply before opacity transition
            setTimeout(() => modal.classList.add('show'), 10);
        };

        // Function to close modal
        const closeLogoutModal = () => {
            modal.classList.remove('show');

            // Restore Scrolling
            document.documentElement.style.overflow = '';
            document.body.style.overflow = '';

            modal.removeEventListener('touchmove', preventTouch);

            setTimeout(() => {
                modal.style.display = 'none';
            }, 300); // Wait for transition
        };

        // 1. Desktop/Generic Logout Link Interception (Event Delegation)
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a');
            if (link) {
                // Check for specific class OR if href points to logout.php
                if (link.classList.contains('navbar__link--logout') || link.href.includes('/auth/logout.php')) {
                    openLogoutModal(e, link.href);
                }
            }
        });

        // 2. Mobile Logout Form Interception
        const mobileLogoutForm = document.querySelector('.dv-mnav-logout');
        if (mobileLogoutForm) {
            mobileLogoutForm.addEventListener('submit', (e) => {
                e.preventDefault();
                logoutTargetUrl = mobileLogoutForm.action; // Assuming GET or simple submit
                // For POST forms, we might need to submit the form element itself, 
                // but here the logout is a simple GET script usually, or a POST without payload.
                // navbar.php line 389 shows method="get". So URL navigation is fine.
                openLogoutModal(e, mobileLogoutForm.action);
            });
        }

        // Modal Actions
        confirmBtn.addEventListener('click', () => {
            if (logoutTargetUrl) {
                window.location.href = logoutTargetUrl;
            }
        });

        cancelBtn.addEventListener('click', closeLogoutModal);

        // Close on outside click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeLogoutModal();
        });
    });
</script>
