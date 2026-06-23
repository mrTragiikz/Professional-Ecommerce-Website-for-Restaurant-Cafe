/**
 * Prevent Zoom Script - Enhanced for Mobile
 * Prevents double-tap zoom and quick tap zoom on quantity buttons
 */

(function () {
    'use strict';
    
    // Prevent zoom on double-tap
    let lastTouchEnd = 0;
    document.addEventListener('touchend', function (event) {
        const now = Date.now();
        if (now - lastTouchEnd <= 300) {
            event.preventDefault();
        }
        lastTouchEnd = now;
    }, { passive: false });
    
    // Prevent zoom on quantity buttons and other interactive elements
    const preventZoomSelectors = [
        '.qty-btn',
        '.cart-qty-btn',
        '.quantity-controls',
        '.cart-qty-controls',
        'button',
        'a',
        'input[type="button"]',
        'input[type="submit"]'
    ];
    
    preventZoomSelectors.forEach(selector => {
        const elements = document.querySelectorAll(selector);
        elements.forEach(element => {
            // Add touch-action CSS property via JavaScript
            element.style.touchAction = 'manipulation';
            element.style.webkitTapHighlightColor = 'transparent';
            
            // Prevent default touch behavior
            element.addEventListener('touchstart', function(e) {
                // Only prevent if it's a quick tap (not a long press)
                if (e.touches.length === 1) {
                    const touch = e.touches[0];
                    element._touchStartTime = Date.now();
                    element._touchStartX = touch.clientX;
                    element._touchStartY = touch.clientY;
                }
            }, { passive: true });
            
            element.addEventListener('touchend', function(e) {
                if (element._touchStartTime) {
                    const touchDuration = Date.now() - element._touchStartTime;
                    const touch = e.changedTouches[0];
                    const deltaX = Math.abs(touch.clientX - (element._touchStartX || 0));
                    const deltaY = Math.abs(touch.clientY - (element._touchStartY || 0));
                    
                    // If it's a quick tap (less than 300ms) and small movement (less than 10px), prevent zoom
                    if (touchDuration < 300 && deltaX < 10 && deltaY < 10) {
                        e.preventDefault();
                    }
                    
                    delete element._touchStartTime;
                    delete element._touchStartX;
                    delete element._touchStartY;
                }
            }, { passive: false });
        });
    });
    
    // Set viewport meta tag programmatically if not already set
    if (!document.querySelector('meta[name="viewport"]')) {
        const viewport = document.createElement('meta');
        viewport.name = 'viewport';
        viewport.content = 'width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover';
        document.getElementsByTagName('head')[0].appendChild(viewport);
    }
    
    // Prevent pinch zoom
    let lastTouchDistance = 0;
    document.addEventListener('touchstart', function(e) {
        if (e.touches.length === 2) {
            const touch1 = e.touches[0];
            const touch2 = e.touches[1];
            lastTouchDistance = Math.hypot(
                touch2.clientX - touch1.clientX,
                touch2.clientY - touch1.clientY
            );
        }
    }, { passive: true });
    
    document.addEventListener('touchmove', function(e) {
        if (e.touches.length === 2) {
            const touch1 = e.touches[0];
            const touch2 = e.touches[1];
            const currentDistance = Math.hypot(
                touch2.clientX - touch1.clientX,
                touch2.clientY - touch1.clientY
            );
            
            // Prevent pinch zoom
            if (Math.abs(currentDistance - lastTouchDistance) > 5) {
                e.preventDefault();
            }
        }
    }, { passive: false });
    
    console.log('Zoom prevention initialized');
})();
