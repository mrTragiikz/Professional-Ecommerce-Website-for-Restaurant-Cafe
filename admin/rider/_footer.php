<?php
if (!defined('RiderAppCore'))
    exit;
?>
<script>
    // RIDER LIVE LOCATION TRACKER
    function sendLocation(lat, lng) {
        fetch('api/update_location.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lat: lat, lng: lng })
        })
            .then(res => res.json())
            .then(data => {
                if (data.error) console.log("Location Update Error: ", data.error);
                // Silent success
            })
            .catch(err => console.error("Location Fetch Error: ", err));
    }

    function trackRiderLocation() {
        if ("geolocation" in navigator) {
            // Get accurate position and update every 10 seconds
            navigator.geolocation.watchPosition(
                (position) => {
                    const lat = position.coords.latitude;
                    const lng = position.coords.longitude;
                    sendLocation(lat, lng);
                },
                (error) => {
                    console.warn("Geolocation Error: " + error.message);
                    // Stop aggressively attempting if permission denied
                    if (error.code !== error.PERMISSION_DENIED) {
                        setTimeout(trackRiderLocation, 10000);
                    }
                },
                {
                    enableHighAccuracy: true,
                    maximumAge: 10000,
                    timeout: 5000
                }
            );
        } else {
            console.log("Geolocation is not supported by this browser.");
        }
    }


    // Start tracking immediately if user is logged into rider app
    document.addEventListener('DOMContentLoaded', function () {
        trackRiderLocation();

        let firstFetch = true;

        setInterval(() => {
            fetch('api/check_status.php')
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'inactive') {
                        window.location.href = 'logout.php?error=account_deactivated';
                        return;
                    }

                    if (data.ready_orders_count !== undefined) {
                        const readyCount = parseInt(data.ready_orders_count);
                        const storedCount = parseInt(localStorage.getItem('jk_lastRiderReadyCount') || '0', 10);

                        // Store ready count for tracking changes
                        if (!firstFetch && readyCount > storedCount) {
                            // (Audio notification removed per request)
                        }

                        localStorage.setItem('jk_lastRiderReadyCount', readyCount);
                        firstFetch = false;

                        // Update ALL badges
                        const readyBadges = document.querySelectorAll('.tab-item[href*="available"] span, .stat-card:nth-child(1) .stat-value');
                        readyBadges.forEach(badge => {
                            badge.textContent = badge.closest('.tab-item') ? `(${readyCount})` : readyCount;
                        });
                    }
                })
                .catch(err => { });
        }, 3000); 
    });
</script>


</body>
</html>
