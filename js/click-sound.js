/**
 * Universal Click Sound System
 * Plays a tick/click sound on all button clicks across the website
 */

(function() {
    'use strict';

    // Create audio context for generating sounds
    let audioContext = null;
    
    // Initialize audio context (required for user interaction)
    function initAudioContext() {
        if (!audioContext) {
            try {
                audioContext = new (window.AudioContext || window.webkitAudioContext)();
            } catch (e) {
                console.warn('Web Audio API not supported');
                return false;
            }
        }
        return true;
    }

    /**
     * Generate and play a click/tick sound
     */
    function playClickSound() {
        if (!initAudioContext()) return;

        try {
            // Create a short, sharp click sound
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();

            // Connect nodes
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);

            // Configure the sound - short, high-pitched tick
            oscillator.frequency.setValueAtTime(800, audioContext.currentTime); // Start at 800Hz
            oscillator.frequency.exponentialRampToValueAtTime(400, audioContext.currentTime + 0.05); // Drop to 400Hz quickly
            
            oscillator.type = 'sine'; // Smooth sine wave

            // Envelope for quick attack and decay (makes it sound like a click)
            const now = audioContext.currentTime;
            gainNode.gain.setValueAtTime(0, now);
            gainNode.gain.linearRampToValueAtTime(0.3, now + 0.001); // Quick attack
            gainNode.gain.exponentialRampToValueAtTime(0.01, now + 0.05); // Quick decay
            gainNode.gain.setValueAtTime(0, now + 0.1); // End

            // Play the sound
            oscillator.start(now);
            oscillator.stop(now + 0.1); // Very short duration (100ms)
        } catch (e) {
            // Silently fail if audio context is not available
            console.warn('Could not play click sound:', e);
        }
    }

    /**
     * Check if element should play sound
     */
    function shouldPlaySound(element) {
        // Skip if element is disabled
        if (element.disabled) return false;
        
        // Skip if element has no-sound class
        if (element.classList.contains('no-sound')) return false;
        
        // Always play sound for button-styled links (including index.html buttons)
        const buttonLinkClasses = ['btn', 'button', 'hero__cta', 'momos-section__button', 
                                    'top-dishes__button', 'reservation-section__button',
                                    'navbar__link', 'lang-btn'];
        
        for (let className of buttonLinkClasses) {
            if (element.classList.contains(className)) {
                return true; // Always play sound for button-styled links
            }
        }
        
        // Skip if it's a regular link that navigates away (not button-styled)
        if (element.tagName === 'A' && element.href && !element.href.includes('#')) {
            return false;
        }
        
        return true;
    }

    /**
     * Handle click events
     */
    function handleClick(event) {
        const target = event.target;
        
        // Check if clicked element is a button or inside a button
        let button = target;
        
        // If clicked element is not a button, check if it's inside one
        if (button.tagName !== 'BUTTON' && button.tagName !== 'A') {
            button = target.closest('button, a.btn, a.button, .btn, .button, a.hero__cta, a.momos-section__button, a.top-dishes__button, a.reservation-section__button, a.navbar__link, a.lang-btn, .hero__cta, .momos-section__button, .top-dishes__button, .reservation-section__button, .navbar__link, .lang-btn');
        }
        
        // Also check for common button classes
        if (!button) {
            const buttonClasses = ['btn', 'button', 'cart-btn', 'menu-cart-btn', 'order-now-btn', 
                                   'confirm-reservation-btn', 'proceed-to-checkout-btn', 
                                   'people-btn', 'qty-btn', 'remove-btn', 'cart-remove-btn',
                                   'service-btn', 'hours-tab-btn', 'dropdown-option',
                                   'remove-confirm-btn', 'remove-confirm-ok', 'remove-confirm-cancel',
                                   'back-to-menu-btn', 'empty-cart-button', 'closed-warning-close',
                                   'operating-hours-close', 'delivery-suburb-close',
                                   'hero__cta', 'momos-section__button', 'top-dishes__button',
                                   'reservation-section__button', 'navbar__link', 'lang-btn'];
            
            for (let className of buttonClasses) {
                if (target.classList.contains(className) || target.closest('.' + className)) {
                    button = target.classList.contains(className) ? target : target.closest('.' + className);
                    break;
                }
            }
        }
        
        // Play sound if it's a valid button
        if (button && shouldPlaySound(button)) {
            playClickSound();
        }
    }

    // Initialize on page load
    function init() {
        // Use event delegation to catch all clicks
        document.addEventListener('click', handleClick, true); // Use capture phase to catch early
        
        // Also initialize audio context on first user interaction
        document.addEventListener('click', function initOnClick() {
            initAudioContext();
            document.removeEventListener('click', initOnClick);
        }, { once: true });
        
        // Initialize on touch for mobile
        document.addEventListener('touchstart', function initOnTouch() {
            initAudioContext();
            document.removeEventListener('touchstart', initOnTouch);
        }, { once: true });
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

