<?php
/**
 * Track Order Map - Rider View
 */
if (!defined('RiderAppCore')) {
    define('RiderAppCore', true);
}
require '_guard.php';
require_once __DIR__ . '/../../config/mapbox.php';
require_once __DIR__ . '/../../config/db.php';

$orderId = intval($_GET['order_id'] ?? 0);
if (!$orderId) {
    header('Location: orders.php');
    exit;
}

global $pdo;
try {
    $stmt = $pdo->prepare("
        SELECT o.*, 
               COALESCE(o.location_lat, u.location_lat) as location_lat, 
               COALESCE(o.location_lng, u.location_lng) as location_lng, 
               u.name as user_name,
               u.phone as user_phone,
               u.delivery_location as user_delivery_location,
               u.street_location as user_street_location,
               COALESCE(o.customer_name, u.name) as display_name,
               COALESCE(o.customer_phone, u.phone) as display_phone,
               r.location_lat as rider_lat,
               r.location_lng as rider_lng,
               r.username as rider_name
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN riders r ON o.rider_id = r.id
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        die("Order #$orderId not found.");
    }

    $userLat = (float) ($order['location_lat'] ?? 0);
    $userLng = (float) ($order['location_lng'] ?? 0);

    // If the currently logged in rider is assigned to the order, or it's unassigned,
    // we can also track the rider. But actually, we just need the customer location.
    // If the rider themselves are assigned, the live tracking updates `riders` table,
    // so we can show them on map too!
    $riderLat = (float) ($order['rider_lat'] ?? 0);
    $riderLng = (float) ($order['rider_lng'] ?? 0);
    $hasRiderCoords = ($riderLat && $riderLng);

    if (!$userLat || !$userLng) {
        $hasCoords = false;
    } else {
        $hasCoords = true;
    }

} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

$pageTitle = "Tracking Map - #ORD-" . str_pad($order['id'], 5, '0', STR_PAD_LEFT);
$current_page = 'orders';
require '_header.php';
require '_sidebar.php';
?>

<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

<div class="rider-topbar">
    <div class="hamburger-btn" onclick="toggleSidebar()" style="display:flex;">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
            stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
        </svg>
    </div>

    <div class="topbar-left" style="display:flex; align-items:center; gap:12px;">
        <div class="logo-icon" style="width:32px; height:32px; box-shadow:none;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path>
            </svg>
        </div>
        <span class="topbar-title">Track Map</span>
    </div>

    <div style="flex:0; width:40px;" class="mobile-spacer"></div>
</div>

<div class="rider-dashboard-wrapper mobile-no-pad">

    <div class="track-map-container" style="flex-direction: column;">

        <div class="track-map-wrapper">
            <?php if ($hasCoords): ?>
                <div id="map"></div>
                <div class="map-controls">
                    <button id="recenterBtn" title="Recenter Map">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                    </button>
                </div>
            <?php else: ?>
                <div class="map-error">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path
                            d="M1 1l22 22M16.72 11.06A10.94 10.94 0 0 1 19 12.55M5 12.55a10.94 10.94 0 0 1 5.17-2.39M10.71 5.05A16 16 0 0 1 22.58 9M1.42 9a15.91 15.91 0 0 1 4.7-2.88M8.53 16.11a6 6 0 0 1 6.95 0M12 20h.01" />
                    </svg>
                    <h3>Coordinates Unavailable</h3>
                    <p>No GPS coordinates for this customer.</p>
                    <a href="orders.php" class="btn btn-primary btn-sm" style="margin-top: 15px;">Return to Orders</a>
                </div>
            <?php endif; ?>
        </div>

        <div class="track-map-sidebar">
            <div class="track-info-card">
                <div class="track-info-header">
                    <span class="order-number">#ORD-
                        <?php echo str_pad($order['id'], 5, '0', STR_PAD_LEFT); ?>
                    </span>
                    <span class="status-badge status-<?php echo strtolower($order['status']); ?>">
                        <?php echo ucfirst($order['status']); ?>
                    </span>
                </div>

                <div class="track-customer-section">
                    <div class="customer-avatar">
                        <?php echo substr($order['display_name'] ?? 'C', 0, 1); ?>
                    </div>
                    <div class="customer-details">
                        <div class="customer-name">
                            <?php echo htmlspecialchars($order['display_name'] ?? 'Customer'); ?>
                        </div>
                        <div class="customer-phone">
                            <?php echo htmlspecialchars($order['display_phone'] ?? 'N/A'); ?>
                        </div>
                    </div>
                </div>

                <div class="track-location-details">
                    <div class="location-item">
                        <div class="location-icon kitchen">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                <polyline points="9 22 9 12 15 12 15 22"></polyline>
                            </svg>
                        </div>
                        <div class="location-info">
                            <label>FROM (KITCHEN)</label>
                            <p>JustKleek Kitchen</p>
                        </div>
                    </div>
                    <div class="location-item">
                        <div class="location-icon user">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                <circle cx="12" cy="10" r="3"></circle>
                            </svg>
                        </div>
                        <div class="location-info">
                            <label>TO (CUSTOMER)</label>
                            <p>
                                <?php echo htmlspecialchars($order['delivery_address'] ?: ($order['user_delivery_location'] ?? 'N/A')); ?>
                                <?php if ($order['location_lat'] && $order['location_lng']): ?>
                                    <div style="font-size: 11px; color: #64748b; font-weight: 500; margin-top: 2px;">
                                        GPS: <?php echo htmlspecialchars($order['location_lat'] . ', ' . $order['location_lng']); ?>
                                    </div>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                    <?php if ($order['rider_name']): ?>
                        <div class="location-item">
                            <div class="location-icon rider" style="color: #059669; border-color: #d1fae5;">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                    stroke-width="2">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <path d="M12 8v4l3 3"></path>
                                </svg>
                            </div>
                            <div class="location-info">
                                <label>RIDER</label>
                                <p>
                                    <?php echo htmlspecialchars($order['rider_name']); ?>
                                </p>
                                <?php if ($hasRiderCoords): ?>
                                    <small>Live tracking active</small>
                                <?php else: ?>
                                    <small>Location Unknown</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="track-actions">
                    <?php if (($order['location_lat'] && $order['location_lng']) || ($userLat && $userLng)): 
                        $navLat = $order['location_lat'] ?: $userLat;
                        $navLng = $order['location_lng'] ?: $userLng;
                        ?>
                        <a href="https://maps.google.com/?q=<?php echo $navLat; ?>,<?php echo $navLng; ?>" target="_blank"
                            class="btn-track-customer" style="text-decoration: none;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                stroke-width="2">
                                <polygon points="3 11 22 2 13 21 11 13 3 11"></polygon>
                            </svg>
                            Navigate to Customer
                        </a>
                    <?php endif; ?>
                    <a href="orders.php" class="btn-back">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <path d="M19 12H5M12 19l-7-7 7-7" />
                        </svg>
                        Back to Orders
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<link href="https://api.mapbox.com/mapbox-gl-js/v3.0.1/mapbox-gl.css" rel="stylesheet">
<script src="https://api.mapbox.com/mapbox-gl-js/v3.0.1/mapbox-gl.js"></script>

<style>
    .track-map-container {
        display: flex;
        height: calc(100vh - 100px);
        background: #f8fafc;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        border: 1px solid #e2e8f0;
    }

    .track-map-sidebar {
        width: 100%;
        background: white;
        padding: 20px;
        z-index: 10;
        box-shadow: 0 -4px 10px rgba(0, 0, 0, 0.05);
        display: flex;
        flex-direction: column;
    }

    .track-map-wrapper {
        flex: 1;
        position: relative;
        background: #e5e7eb;
        min-height: 350px;
    }

    #map {
        position: absolute;
        top: 0;
        bottom: 0;
        width: 100%;
    }

    .track-info-card {
        display: flex;
        flex-direction: column;
    }

    .track-info-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }

    .order-number {
        font-size: 16px;
        font-weight: 800;
        color: #1e293b;
    }

    .status-badge {
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .status-pending {
        background: #fef3c7;
        color: #d97706;
    }

    .status-confirmed {
        background: #dcfce7;
        color: #16a34a;
    }

    .status-ready {
        background: #eff6ff;
        color: #2563eb;
    }

    .status-completed {
        background: #10b981;
        color: white;
    }

    .track-customer-section {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 12px;
        background: #f8fafc;
        border-radius: 12px;
        margin-bottom: 20px;
    }

    .customer-avatar {
        width: 44px;
        height: 44px;
        background: #2563eb;
        color: white;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        font-weight: 700;
    }

    .customer-name {
        font-weight: 700;
        color: #0f172a;
        font-size: 15px;
    }

    .customer-phone {
        font-size: 13px;
        color: #64748b;
    }

    .track-location-details {
        display: flex;
        flex-direction: column;
        gap: 0;
        margin-bottom: 10px;
        position: relative;
    }

    .track-location-details::before {
        content: '';
        position: absolute;
        left: 21px;
        top: 35px;
        bottom: 35px;
        width: 2px;
        background: repeating-linear-gradient(to bottom, #cbd5e1 0, #cbd5e1 4px, transparent 4px, transparent 8px);
        z-index: 1;
    }

    .location-item {
        display: flex;
        gap: 16px;
        padding: 12px 0;
        position: relative;
        z-index: 2;
    }

    .location-icon {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: white;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        flex-shrink: 0;
        border: 2px solid white;
    }

    .location-icon.kitchen {
        color: #f97316;
        border-color: #ffedd5;
    }

    .location-icon.user {
        color: #ef4444;
        border-color: #fee2e2;
    }

    .location-info label {
        display: block;
        font-size: 10px;
        font-weight: 800;
        color: #94a3b8;
        letter-spacing: 0.5px;
        margin-bottom: 2px;
    }

    .location-info p {
        margin: 0;
        font-weight: 600;
        color: #1e293b;
        font-size: 14px;
    }

    .location-info small {
        color: #64748b;
        font-size: 12px;
    }

    .track-actions {
        margin-top: 15px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .btn-track-customer {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        padding: 12px;
        background: linear-gradient(135deg, #3b82f6, #2563eb);
        color: white;
        border: none;
        border-radius: 12px;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .btn-track-customer:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(37, 99, 235, 0.35);
    }

    .btn-back {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        width: 100%;
        padding: 12px;
        background: #f1f5f9;
        color: #475569;
        text-decoration: none;
        border-radius: 12px;
        border: 1.5px solid #e2e8f0;
        font-weight: 700;
        transition: all 0.2s;
    }

    .btn-back:hover {
        background: #e2e8f0;
        color: #334155;
    }

    .map-controls {
        position: absolute;
        top: 15px;
        right: 15px;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    #recenterBtn {
        width: 44px;
        height: 44px;
        background: white;
        border: none;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #1e293b;
    }

    .map-error {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        height: 100%;
        text-align: center;
        padding: 40px;
        color: #64748b;
    }

    .map-error h3 {
        margin: 20px 0 10px;
        color: #1e293b;
        font-size: 18px;
    }

    .marker-kitchen {
        display: flex;
        justify-content: center;
        align-items: center;
        width: 40px;
        height: 40px;
        background: #f97316;
        border: 3px solid white;
        border-radius: 50%;
        color: white;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
    }

    .marker-user {
        display: flex;
        justify-content: center;
        align-items: center;
        width: 40px;
        height: 40px;
        background: #ef4444;
        border: 3px solid white;
        border-radius: 50%;
        color: white;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
    }

    .marker-rider {
        display: flex;
        justify-content: center;
        align-items: center;
        width: 44px;
        height: 44px;
        background: #10b981;
        border: 3px solid white;
        border-radius: 50%;
        color: white;
        box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        z-index: 10;
    }

    @media (max-width: 1024px) {
        .mobile-no-pad {
            padding: 0 !important;
            margin: 0 !important;
            max-width: 100% !important;
        }

        .track-map-container {
            border-radius: 0 !important;
            border-left: none !important;
            border-right: none !important;
            border-top: none !important;
            height: auto !important;
            min-height: calc(100vh - 65px) !important;
            overflow: visible !important;
        }

        .track-map-wrapper {
            height: 50vh;
            flex: none;
        }

        .track-map-sidebar {
            padding: 20px;
            box-shadow: 0 -8px 20px rgba(0, 0, 0, 0.08);
            border-radius: 20px 20px 0 0;
            margin-top: -20px;
            z-index: 20;
            background: #ffffff;
        }
    }

    @media (min-width: 1024px) {
        .track-map-container {
            flex-direction: row !important;
            height: 85vh;
        }

        .track-map-sidebar {
            width: 340px;
            box-shadow: 4px 0 10px rgba(0, 0, 0, 0.05);
        }
    }
</style>

<script>
    function toggleSidebar() {
        document.getElementById('sidebarNav').classList.toggle('active');
        document.getElementById('sidebarOverlay').classList.toggle('active');
    }
</script>

<?php if ($hasCoords): ?>
    <script>
        mapboxgl.accessToken = '<?php echo MAPBOX_PUBLIC_TOKEN; ?>';

        const kitchen = [<?php echo KITCHEN_LNG; ?>, <?php echo KITCHEN_LAT; ?>];
        const customer = [<?php echo $userLng; ?>, <?php echo $userLat; ?>];

        const map = new mapboxgl.Map({
            container: 'map',
            style: 'mapbox://styles/mapbox/streets-v12',
            center: [(kitchen[0] + customer[0]) / 2, (kitchen[1] + customer[1]) / 2],
            zoom: 13
        });

        map.addControl(new mapboxgl.NavigationControl(), 'bottom-right');

        const kitchenEl = document.createElement('div');
        kitchenEl.className = 'marker-kitchen';
        kitchenEl.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path></svg>';

        const userEl = document.createElement('div');
        userEl.className = 'marker-user';
        userEl.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path></svg>';

        new mapboxgl.Marker(kitchenEl)
            .setLngLat(kitchen)
            .setPopup(new mapboxgl.Popup().setHTML('<b>Kitchen</b><br>JustKleek HQ'))
            .addTo(map);

        new mapboxgl.Marker(userEl)
            .setLngLat(customer)
            .setPopup(new mapboxgl.Popup().setHTML('<b>Customer</b><br><?php echo addslashes($order['display_name']); ?>'))
            .addTo(map);

        const bounds = new mapboxgl.LngLatBounds()
            .extend(kitchen)
            .extend(customer);

        <?php if ($hasRiderCoords): ?>
            const rider = [<?php echo $riderLng; ?>, <?php echo $riderLat; ?>];
            const riderEl = document.createElement('div');
            riderEl.className = 'marker-rider';
            riderEl.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M12 8v4l3 3"></path></svg>';

            new mapboxgl.Marker(riderEl)
                .setLngLat(rider)
                .setPopup(new mapboxgl.Popup().setHTML('<b>Rider</b><br><?php echo addslashes($order['rider_name']); ?>'))
                .addTo(map);

            bounds.extend(rider);
        <?php endif; ?>

        map.on('load', () => {

            map.fitBounds(bounds, {
                padding: { top: 50, bottom: 50, left: 50, right: 50 },
                duration: 1500
            });

            getRoute();
        });

        async function getRoute() {
            const query = await fetch(
                `https://api.mapbox.com/directions/v5/mapbox/driving/${kitchen[0]},${kitchen[1]};${customer[0]},${customer[1]}?steps=true&geometries=geojson&access_token=${mapboxgl.accessToken}`,
                { method: 'GET' }
            );
            const json = await query.json();
            const data = json.routes[0];
            const route = data.geometry.coordinates;
            const geojson = {
                type: 'Feature',
                properties: {},
                geometry: {
                    type: 'LineString',
                    coordinates: route
                }
            };

            if (map.getSource('route')) {
                map.getSource('route').setData(geojson);
            } else {
                map.addLayer({
                    id: 'route',
                    type: 'line',
                    source: {
                        type: 'geojson',
                        data: geojson
                    },
                    layout: {
                        'line-join': 'round',
                        'line-cap': 'round'
                    },
                    paint: {
                        'line-color': '#2563eb', // kitchen to customer blue
                        'line-width': 5,
                        'line-opacity': 0.75
                    }
                });
            }
        }

        <?php if ($hasRiderCoords): ?>
            async function getRouteRiderToCustomer() {
                const query = await fetch(
                    `https://api.mapbox.com/directions/v5/mapbox/driving/${rider[0]},${rider[1]};${customer[0]},${customer[1]}?steps=true&geometries=geojson&access_token=${mapboxgl.accessToken}`,
                    { method: 'GET' }
                );
                const json = await query.json();
                const data = json.routes[0];
                const route = data.geometry.coordinates;
                const geojson = {
                    type: 'Feature',
                    properties: {},
                    geometry: {
                        type: 'LineString',
                        coordinates: route
                    }
                };

                if (map.getSource('route')) {
                    map.getSource('route').setData(geojson);
                    // Update route color to rider-centric active color
                    map.setPaintProperty('route', 'line-color', '#10b981');
                }
            }

            window.trackCustomerRoute = function () {
                getRouteRiderToCustomer();
                const btnBounds = new mapboxgl.LngLatBounds()
                    .extend(rider)
                    .extend(customer);
                map.fitBounds(btnBounds, {
                    padding: { top: 50, bottom: 50, left: 50, right: 50 },
                    duration: 1000
                });
            };
        <?php endif; ?>

        document.getElementById('recenterBtn').addEventListener('click', () => {
            const btnBounds = new mapboxgl.LngLatBounds()
                .extend(kitchen)
                .extend(customer);

            <?php if ($hasRiderCoords): ?>
                btnBounds.extend(rider);
            <?php endif; ?>

            map.fitBounds(btnBounds, {
                padding: { top: 50, bottom: 50, left: 50, right: 50 },
                duration: 1000
            });
        });
    </script>
<?php endif; ?>

<?php require '_footer.php'; ?>