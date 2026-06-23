/**
 * Email Verification Check Utility
 * Checks if user is verified before allowing actions
 */

// Check verification status (must be set by PHP on each page)
let userVerificationStatus = {
    isLoggedIn: false,
    isVerified: false
};

/**
 * Initialize verification status from PHP
 * Call this function with data from PHP: initVerificationCheck(isLoggedIn, isVerified)
 */
function initVerificationCheck(isLoggedIn, isVerified) {
    userVerificationStatus.isLoggedIn = isLoggedIn;
    userVerificationStatus.isVerified = isVerified;
}

/**
 * Check if user needs to verify email before performing an action
 * @returns {boolean} True if verification is required, false otherwise
 */
function requiresVerification() {
    // If not logged in, don't show verification modal (will be handled by login redirect)
    if (!userVerificationStatus.isLoggedIn) {
        return false;
    }
    
    // If logged in but not verified, show verification modal
    return !userVerificationStatus.isVerified;
}

/**
 * Check verification and show modal if needed
 * @param {Function} callback Function to call if verification is not required
 * @returns {boolean} True if verification is required (modal shown), false otherwise
 */
function checkVerificationBeforeAction(callback) {
    if (requiresVerification()) {
        if (typeof window.showVerificationModal === 'function') {
            window.showVerificationModal();
        }
        return true; // Verification required
    }
    
    // User is verified, proceed with action
    if (typeof callback === 'function') {
        callback();
    }
    return false; // Verification not required
}

/**
 * Wrapper for event handlers that require verification
 * Usage: addVerificationCheck(button, function() { /* action code */ });
 */
function addVerificationCheck(element, callback) {
    if (!element) return;
    
    element.addEventListener('click', function(e) {
        if (checkVerificationBeforeAction(callback)) {
            e.preventDefault();
            e.stopPropagation();
        }
    });
}


