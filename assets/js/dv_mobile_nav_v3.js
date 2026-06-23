/**
 * Mobile Navigation V3
 * Handles Drawer Open/Close Logic
 * Scoped to avoid conflicts
 */

document.addEventListener('DOMContentLoaded', () => {
    // Elements
    const mnavBtn = document.getElementById('dvMnavBtn');
    const mnavDrawer = document.getElementById('dvMnavDrawer');
    const mnavOverlay = document.getElementById('dvMnavOverlay');
    const mnavClose = document.getElementById('dvMnavClose');

    // Guard: if elements are missing (desktop view?), do nothing
    if (!mnavBtn || !mnavDrawer || !mnavOverlay) return;

    // Open Function
    const openDrawer = () => {
        mnavDrawer.classList.add('dv-mnav-open');
        mnavOverlay.classList.add('dv-mnav-visible');
        mnavOverlay.hidden = false;

        // Prevent background scrolling (both body and html for mobile browsers)
        document.body.style.overflow = 'hidden';
        document.documentElement.style.overflow = 'hidden';

        // Accessibility
        mnavBtn.setAttribute('aria-expanded', 'true');
        mnavDrawer.setAttribute('aria-hidden', 'false');
    };

    // Close Function
    const closeDrawer = () => {
        mnavDrawer.classList.remove('dv-mnav-open');
        mnavOverlay.classList.remove('dv-mnav-visible');

        // Allow transition to finish before hiding overlay completely
        setTimeout(() => {
            if (!mnavOverlay.classList.contains('dv-mnav-visible')) {
                mnavOverlay.hidden = true;
            }
        }, 300); // Matches CSS transition duration

        // Restore background scrolling
        document.body.style.overflow = '';
        document.documentElement.style.overflow = '';

        // Accessibility
        mnavBtn.setAttribute('aria-expanded', 'false');
        mnavDrawer.setAttribute('aria-hidden', 'true');
    };

    // Event Listeners
    mnavBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        openDrawer();
    });

    if (mnavClose) {
        mnavClose.addEventListener('click', (e) => {
            e.stopPropagation();
            closeDrawer();
        });
    }

    mnavOverlay.addEventListener('click', (e) => {
        e.stopPropagation();
        closeDrawer();
    });

    // Close on ESC key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && mnavDrawer.classList.contains('dv-mnav-open')) {
            closeDrawer();
        }
    });

    // Close smoothly when clicking any link inside the drawer to prevent animation freezing
    const drawerLinks = mnavDrawer.querySelectorAll('a');
    drawerLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            const href = link.getAttribute('href');

            // If it's a real page link
            if (href && !href.startsWith('#') && !link.hasAttribute('target')) {
                e.preventDefault(); // Stop browser from immediately freezing the page
                closeDrawer(); // Slide drawer away

                // Wait for the drawer animation to almost finish before loading new page
                setTimeout(() => {
                    window.location.href = href;
                }, 250);
            } else {
                closeDrawer();
            }
        });
    });

    const formButtons = mnavDrawer.querySelectorAll('button[type="submit"]');
    formButtons.forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            closeDrawer();

            // For the logout button
            setTimeout(() => {
                const form = btn.closest('form');
                if (form) form.submit();
            }, 250);
        });
    });
});
