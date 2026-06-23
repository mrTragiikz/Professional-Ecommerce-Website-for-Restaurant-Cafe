/**
 * Unified Location System
 * Handles "Use Current Location" functionality across signup, onboarding, and profile pages.
 */

(function () {
    const CONFIG = {
        // IDs used across different pages
        buttonIds: ['btnUseCurrent', 'editUseCurrent'],
        fields: {
            street: ['street_location', 'editStreetLocation'],
            delivery: ['delivery_location', 'editDeliveryLocation'],
            lat: ['location_lat', 'editLocationLat'],
            lng: ['location_lng', 'editLocationLng']
        }
    };

    // Use event delegation to handle buttons that might be added to DOM later (e.g. in modals)
    document.addEventListener('click', function (e) {
        // Find if the clicked element or its parent is one of our buttons
        const button = e.target.closest(CONFIG.buttonIds.map(id => '#' + id).join(', '));

        if (button) {
            e.preventDefault();
            handleLocationClick(button);
        }
    });

    function findElement(idList) {
        for (const id of idList) {
            const el = document.getElementById(id);
            if (el) return el;
        }
        return null;
    }

    function handleLocationClick(button) {
        const btnText = button.querySelector('.btn-text') || button.querySelector('span');
        const originalHTML = button.innerHTML;

        const streetInput = findElement(CONFIG.fields.street);
        const deliveryInput = findElement(CONFIG.fields.delivery);
        const latInput = findElement(CONFIG.fields.lat);
        const lngInput = findElement(CONFIG.fields.lng);

        if (!navigator.geolocation) {
            showSimpleError('Geolocation is not supported by your browser');
            return;
        }

        if (!window.isSecureContext && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
            showSimpleError('Location access requires HTTPS (or localhost). Please use HTTPS or enter your address manually.');
            if (streetInput) streetInput.readOnly = false;
            return;
        }

        // Loading state
        button.disabled = true;
        button.classList.add('loading');
        if (button.style) button.style.opacity = '0.7';
        if (btnText) btnText.textContent = "Locating...";

        getLocationWithFallback(
            () => {
                if (btnText) btnText.textContent = "Trying again...";
            }
        ).then((position) => {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;

            // Set coordinates immediately
            if (latInput) latInput.value = lat;
            if (lngInput) lngInput.value = lng;

            // Try Reverse Geocoding
            performReverseGeocoding(lat, lng, streetInput, deliveryInput, button, originalHTML);
        }).catch((error) => {
            console.error("Geolocation error:", error);
            let msg = 'Please allow location access in your browser settings or enter your address manually.';
            if (error && error.code === 1) msg = 'Location access denied. Please enable it in browser settings.';
            else if (error && error.code === 2) msg = 'Location is unavailable right now. Try turning on GPS/Wi‑Fi, or enter your address manually.';
            else if (error && error.code === 3) msg = 'Could not get your location in time. You can try again or enter your address manually.';

            const isDenied = error && error.code === 1;

            if (streetInput) streetInput.readOnly = false;
            // No alert or modal for geolocation errors as requested by user.
            // Using a non-intrusive toast instead if msg is important, or just console.warn.
            console.warn("Geolocation warning:", msg);
            showToast(msg); // Toasts are less intrusive than modals/alerts.
            resetButton(button, originalHTML);
        });
    }

    function getCurrentPositionAsync(options) {
        return new Promise((resolve, reject) => {
            navigator.geolocation.getCurrentPosition(resolve, reject, options);
        });
    }

    async function getLocationWithFallback(onRetry) {
        try {
            // Fast path: coarse location (usually quicker on desktops/emulators)
            return await getCurrentPositionAsync({
                enableHighAccuracy: false,
                timeout: 15000,
                maximumAge: 300000
            });
        } catch (e1) {
            // Retry once with high accuracy if the first attempt times out/unavailable
            const shouldRetry = e1 && (e1.code === 2 || e1.code === 3);
            if (!shouldRetry) throw e1;
            if (typeof onRetry === 'function') onRetry();
            return await getCurrentPositionAsync({
                enableHighAccuracy: true,
                timeout: 25000,
                maximumAge: 0
            });
        }
    }

    function performReverseGeocoding(lat, lng, streetInput, deliveryInput, button, originalHTML, retryCount = 0) {
        // We no longer perform server-side reverse geocoding to auto-fill the area name.
        // As per user request, simply input the coordinates.
        if (streetInput) {
            streetInput.value = `${lat.toFixed(6)}, ${lng.toFixed(6)}`;
            streetInput.dispatchEvent(new Event('input'));
            streetInput.dispatchEvent(new Event('change'));
        }

        resetButton(button, originalHTML);
        if (streetInput) streetInput.focus();
    }

    function fallbackToCoords(input, lat, lng) {
        if (input) {
            input.value = `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
            input.dispatchEvent(new Event('input'));
            input.dispatchEvent(new Event('change'));
        }
    }

    function resetButton(button, originalHTML) {
        button.disabled = false;
        button.classList.remove('loading');
        if (button.style) button.style.opacity = '1';
        button.innerHTML = originalHTML;

        // Focus street input if it exists
        const streetInput = findElement(CONFIG.fields.street);
        if (streetInput) streetInput.focus();
    }

    function showSimpleError(message) {
        if (typeof showCustomModal === 'function') {
            showCustomModal('Location Notice', message, 'warning');
        } else if (typeof showErrorMessage === 'function') {
            showErrorMessage(message);
        } else {
            console.warn("No modal function found, showing toast:", message);
            showToast(message);
        }
    }

    function showToast(message) {
        try {
            const existing = document.getElementById('jkLocationToast');
            if (existing) existing.remove();

            const toast = document.createElement('div');
            toast.id = 'jkLocationToast';
            toast.textContent = message;
            toast.setAttribute('role', 'status');
            toast.style.position = 'fixed';
            toast.style.left = '50%';
            toast.style.bottom = '18px';
            toast.style.transform = 'translateX(-50%)';
            toast.style.maxWidth = 'min(92vw, 520px)';
            toast.style.padding = '12px 14px';
            toast.style.borderRadius = '12px';
            toast.style.background = 'rgba(17, 24, 39, 0.95)';
            toast.style.color = '#fff';
            toast.style.fontSize = '13px';
            toast.style.lineHeight = '1.35';
            toast.style.boxShadow = '0 10px 25px rgba(0,0,0,0.25)';
            toast.style.zIndex = '999999';
            toast.style.cursor = 'pointer';
            toast.style.userSelect = 'none';

            toast.addEventListener('click', () => toast.remove());
            document.body.appendChild(toast);

            setTimeout(() => {
                if (toast && toast.parentNode) toast.remove();
            }, 6500);
        } catch (_) {
            // If DOM isn’t ready or something unusual happens, last resort: console only.
        }
    }
})();
