<?php
/**
 * JustKleek Admin Route Diagnostic Tool
 * Purpose: Verify Mapbox Directions API accuracy between kitchen and user.
 * Access: Admin only + debug=1 parameter
 */

// 1. Session & Auth Guard
require_once __DIR__ . '/../includes/auth.php';
requireAdminLogin();

// 2. Developer Debug Key Parameter Check
if (!isset($_GET['debug']) || $_GET['debug'] !== '1') {
    die("Developer diagnostic tool. Use ?debug=1 to access.");
}

// 3. Include necessary files
require_once __DIR__ . '/../../config/mapbox.php';
require_once __DIR__ . '/../../app/services/mapbox_distance.php';

// Inputs
$uLat = isset($_GET['user_lat']) && $_GET['user_lat'] !== '' ? (float) $_GET['user_lat'] : null;
$uLng = isset($_GET['user_lng']) && $_GET['user_lng'] !== '' ? (float) $_GET['user_lng'] : null;

// Kitchen fallback from config
$kLat_default = defined('KITCHEN_LAT') ? KITCHEN_LAT : 27.703861;
$kLng_default = defined('KITCHEN_LNG') ? KITCHEN_LNG : 84.452333;

$kLat = isset($_GET['kitchen_lat']) && $_GET['kitchen_lat'] !== '' ? (float) $_GET['kitchen_lat'] : $kLat_default;
$kLng = isset($_GET['kitchen_lng']) && $_GET['kitchen_lng'] !== '' ? (float) $_GET['kitchen_lng'] : $kLng_default;

$insecure = isset($_GET['insecure']) && $_GET['insecure'] == '1';

/**
 * Calculate Haversine straight-line distance (km)
 */
function computeHaversineDistance($lat1, $lon1, $lat2, $lon2)
{
    if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null)
        return 0;
    $earthRadius = 6371; // km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
        sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JustKleek Admin Route Diagnostic Tool</title>
    <style>
        :root {
            --primary: #3498db;
            --secondary: #2c3e50;
            --success: #27ae60;
            --error: #e74c3c;
            --warning: #f39c12;
            --bg: #f4f7f6;
            --card: #ffffff;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
            background: var(--bg);
        }

        h1 {
            color: var(--secondary);
            text-align: center;
            border-bottom: 3px solid var(--primary);
            padding-bottom: 10px;
            margin-bottom: 30px;
        }

        h2 {
            color: var(--primary);
            margin-top: 30px;
            border-left: 5px solid var(--primary);
            padding-left: 15px;
            font-size: 1.4em;
        }

        section {
            background: var(--card);
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 25px;
            border: 1px solid #e1e8ed;
        }

        pre {
            background: #1e272e;
            color: #d2dae2;
            padding: 15px;
            border-radius: 8px;
            overflow-x: auto;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 13px;
            border: 1px solid #34495e;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .label {
            font-weight: 600;
            color: var(--secondary);
            display: block;
            margin-bottom: 5px;
        }

        .val {
            font-family: 'Consolas', monospace;
            color: var(--primary);
        }

        .status-ok {
            color: var(--success);
            font-weight: bold;
        }

        .status-error {
            color: var(--error);
            font-weight: bold;
        }

        .warning {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }

        .info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
        }

        .danger-zone {
            border: 2px dashed var(--error);
            background: #fff5f5;
        }

        input[type="text"] {
            padding: 10px;
            border: 1px solid #cbd5e0;
            border-radius: 6px;
            width: 100%;
            box-sizing: border-box;
        }

        button {
            padding: 12px 24px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
            width: 100%;
        }

        button:hover {
            background: #2980b9;
            transform: translateY(-1px);
        }

        .redacted {
            background: #eee;
            color: #999;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 0.9em;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        td,
        th {
            padding: 10px;
            border-bottom: 1px solid #ecf0f1;
            text-align: left;
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.85em;
            font-weight: 600;
        }

        .badge-driving {
            background: #e3f2fd;
            color: #1976d2;
        }
    </style>
</head>

<body>

    <h1>JustKleek Admin Route Diagnostic Tool</h1>

    <!-- Form Section -->
    <section>
        <h2>F) Frontend Payload Check Helper</h2>
        <form method="GET">
            <input type="hidden" name="debug" value="1">
            <div class="grid">
                <div>
                    <label class="label">User Latitude</label>
                    <input type="text" name="user_lat" value="<?php echo htmlspecialchars($_GET['user_lat'] ?? ''); ?>"
                        placeholder="e.g. 27.7031">
                </div>
                <div>
                    <label class="label">User Longitude</label>
                    <input type="text" name="user_lng" value="<?php echo htmlspecialchars($_GET['user_lng'] ?? ''); ?>"
                        placeholder="e.g. 84.4410">
                </div>
            </div>
            <p style="font-size: 0.9em; color: #666; margin-bottom: 10px;"><em>Optional: Override kitchen (defaults to
                    constants from config)</em></p>
            <div class="grid">
                <div>
                    <label class="label">Kitchen Latitude</label>
                    <input type="text" name="kitchen_lat"
                        value="<?php echo isset($_GET['kitchen_lat']) ? htmlspecialchars($_GET['kitchen_lat']) : ''; ?>"
                        placeholder="<?php echo $kLat_default; ?>">
                </div>
                <div>
                    <label class="label">Kitchen Longitude</label>
                    <input type="text" name="kitchen_lng"
                        value="<?php echo isset($_GET['kitchen_lng']) ? htmlspecialchars($_GET['kitchen_lng']) : ''; ?>"
                        placeholder="<?php echo $kLng_default; ?>">
                </div>
            </div>
            <div style="margin: 20px 0;">
                <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                    <input type="checkbox" name="insecure" value="1" <?php echo $insecure ? 'checked' : ''; ?>
                    style="width: 20px; height: 20px;">
                    <span>Skip SSL Verification (<span class="status-error">INSECURE</span> - for local debug
                        only)</span>
                </label>
            </div>
            <button type="submit">🚀 Test Distance & Fee</button>
        </form>
    </section>

    <?php if ($uLat !== null && $uLng !== null): ?>

        <!-- Raw Inputs Section -->
        <section>
            <h2>A) Raw Inputs</h2>
            <div class="grid">
                <div>
                    <span class="label">User Received</span>
                    <span class="val">
                        <?php echo $uLat; ?>,
                        <?php echo $uLng; ?>
                    </span>
                </div>
                <div>
                    <span class="label">Kitchen Used</span>
                    <span class="val">
                        <?php echo $kLat; ?>,
                        <?php echo $kLng; ?>
                    </span>
                </div>
            </div>

            <table>
                <?php
                $uValid = ($uLat >= -90 && $uLat <= 90 && $uLng >= -180 && $uLng <= 180);
                $kValid = ($kLat >= -90 && $kLat <= 90 && $kLng >= -180 && $kLng <= 180);
                ?>
                <tr>
                    <th>Check</th>
                    <th>Status</th>
                </tr>
                <tr>
                    <td>Numeric Validation</td>
                    <td>
                        <?php echo (is_numeric($uLat) && is_numeric($uLng)) ? '<span class="status-ok">Valid Numbers</span>' : '<span class="status-error">Non-numeric detected</span>'; ?>
                    </td>
                </tr>
                <tr>
                    <td>User Range (-90..90, -180..180)</td>
                    <td>
                        <?php echo $uValid ? '<span class="status-ok">Within Bounds</span>' : '<span class="status-error">OUT OF RANGE</span>'; ?>
                    </td>
                </tr>
                <tr>
                    <td>Kitchen Range Boundary</td>
                    <td>
                        <?php echo $kValid ? '<span class="status-ok">Within Bounds</span>' : '<span class="status-error">OUT OF RANGE</span>'; ?>
                    </td>
                </tr>
            </table>
        </section>

        <!-- Coordinate Order Section -->
        <section>
            <h2>B) Coordinate Order Sanity Check</h2>
            <div class="info">
                <strong>Mapbox Requirement:</strong> The URL path must use <code>{longitude},{latitude}</code>.
            </div>
            <table>
                <tr>
                    <th>Target</th>
                    <th>Lat, Lng (Human)</th>
                    <th>Lng, Lat (Mapbox Order)</th>
                </tr>
                <tr>
                    <td>User</td>
                    <td>
                        <?php echo "$uLat, $uLng"; ?>
                    </td>
                    <td><strong class="val">
                            <?php echo "$uLng,$uLat"; ?>
                        </strong></td>
                </tr>
                <tr>
                    <td>Kitchen</td>
                    <td>
                        <?php echo "$kLat, $kLng"; ?>
                    </td>
                    <td><strong class="val">
                            <?php echo "$kLng,$kLat"; ?>
                        </strong></td>
                </tr>
            </table>

            <p class="label" style="margin-top: 20px;">Constructed API URL (Token Redacted):</p>
            <pre>https://api.mapbox.com/directions/v5/mapbox/driving/<?php echo "$kLng,$kLat;$uLng,$uLat"; ?>?access_token=<span class="redacted">REDACTED</span>&overview=full&geometries=geojson&steps=true</pre>
        </section>

        <!-- Mapbox Directions Test -->
        <section>
            <h2>C) Mapbox Directions Test <span class="badge badge-driving">DRIVING</span></h2>
            <?php
            $token = MAPBOX_SECRET_TOKEN;
            // Mapbox URL construction
            $url = "https://api.mapbox.com/directions/v5/mapbox/driving/{$kLng},{$kLat};{$uLng},{$uLat}?access_token={$token}&overview=full&geometries=geojson&steps=true";

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);

            // SSL CA Bundle Path
            $caPath = __DIR__ . '/../../config/cacert.pem';
            if (!$insecure && file_exists($caPath)) {
                curl_setopt($ch, CURLOPT_CAINFO, $caPath);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            } elseif ($insecure) {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            }

            // Always set a UA for Mapbox
            curl_setopt($ch, CURLOPT_USERAGENT, 'JustKleek/Diagnostic');

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
            curl_close($ch);

            if ($response === false) {
                echo "<p class='status-error'>CURL Request Failed!</p>";
                echo "<pre>CURL Error ($curlErrno): $curlError</pre>";

                // Section G) SSL/cURL Handling logic
                if ($curlErrno === 60 || $curlErrno === 77) {
                    echo "<div class='warning' style='border: 2px solid var(--error)'>";
                    echo "<h3>Section G) SSL/cURL Handling</h3>";
                    echo "<strong>Issue:</strong> Server SSL CA bundle missing (Certificate verification failed).<br><br>";
                    echo "<strong>Exact Fix Suggestion:</strong><br>";
                    echo "1. Download latest CA bundle: <a href='https://curl.se/ca/cacert.pem' target='_blank'>cacert.pem</a><br>";
                    echo "2. Edit your <code>php.ini</code> and find <code>curl.cainfo</code><br>";
                    echo "3. Set it: <code>curl.cainfo = \"C:\path\to\cacert.pem\"</code><br>";
                    echo "4. Restart WAMP/Web Server.<br><br>";
                    echo "<em>Alternatively, toggle the 'Insecure' checkbox above for temporary testing.</em>";
                    echo "</div>";
                }
            } else {
                echo "<table>";
                echo "<tr><th>HTTP Status</th><td>" . ($httpCode == 200 ? "<span class='status-ok'>200 OK</span>" : "<span class='status-error'>$httpCode</span>") . "</td></tr>";

                $data = json_decode($response, true);

                if ($httpCode !== 200) {
                    echo "</table>";
                    echo "<div class='warning'><strong>API Error Response:</strong></div>";
                    echo "<pre>" . htmlspecialchars($response) . "</pre>";
                } else if (isset($data['routes'][0])) {
                    $route = $data['routes'][0];
                    $distMeters = $route['distance'];
                    $distKm = $distMeters / 1000;
                    $durationSec = $route['duration'];

                    $rate = defined('DELIVERY_RATE_PER_KM') ? DELIVERY_RATE_PER_KM : 20;
                    $baseFee = defined('DELIVERY_BASE_FEE') ? DELIVERY_BASE_FEE : 20;

                    if ($distKm < 1) {
                        $fee = $baseFee;
                    } else {
                        $fee = $baseFee + ($distKm * $rate);
                    }
                    $fee = round($fee, 2);

                    echo "<tr><th>Distance (Meters)</th><td class='val'>" . number_format($distMeters, 2) . " m</td></tr>";
                    echo "<tr><th>Distance (KM)</th><td class='val'>" . number_format($distKm, 4) . " km</td></tr>";
                    echo "<tr><th>Duration</th><td>" . number_format($durationSec / 60, 2) . " minutes</td></tr>";
                    echo "<tr><th>Computed Fee</th><td style='font-size: 1.2em; color: var(--success); font-weight: bold;'>Rs. " . number_format($fee, 2) . " <small>(Base: Rs. $baseFee + Rs. $rate/km)</small></td></tr>";
                    echo "</table>";

                    // Section D) Mapbox Route Sanity
                    echo "<h2>D) Mapbox Route Sanity</h2>";
                    $geometry = $route['geometry'];
                    $coords = $geometry['coordinates'];
                    $firstPoint = $coords[0]; // [lng, lat]
                    $lastPoint = $coords[count($coords) - 1];

                    // Proximity check using Haversine
                    $startOffset = computeHaversineDistance($kLat, $kLng, $firstPoint[1], $firstPoint[0]);
                    $endOffset = computeHaversineDistance($uLat, $uLng, $lastPoint[1], $lastPoint[0]);

                    echo "<table>";
                    echo "<tr><th>Point</th><th>Coords (Lng, Lat)</th><th>Distance from Target</th></tr>";
                    echo "<tr><td>Route Start</td><td class='val'>" . implode(',', $firstPoint) . "</td><td>" . number_format($startOffset, 4) . " km</td></tr>";
                    echo "<tr><td>Route End</td><td class='val'>" . implode(',', $lastPoint) . "</td><td>" . number_format($endOffset, 4) . " km</td></tr>";
                    echo "</table>";

                    if ($startOffset > 0.5 || $endOffset > 0.5) {
                        echo "<div class='warning'><strong>⚠️ Warning: Route snapping far away!</strong><br>The route starts or ends more than 500m from your requested coordinates. This usually means:<br>- Coordinates are in a location with no nearby roads.<br>- Coordinates are swapped.<br>- Mapbox is snapping to a major highway far from the point.</div>";
                    } else {
                        echo "<p class='status-ok'>Route successfully aligned with requested points.</p>";
                    }

                    // Section E) Haversine Comparison
                    echo "<h2>E) Compare With Haversine (Debug Only)</h2>";
                    $haversineKm = computeHaversineDistance($kLat, $kLng, $uLat, $uLng);
                    $ratio = $haversineKm > 0 ? ($distKm / $haversineKm) : 1;

                    echo "<table>";
                    echo "<tr><th>Method</th><th>Distance</th></tr>";
                    echo "<tr><td>Full Road Route (Mapbox)</td><td class='val'>" . number_format($distKm, 4) . " km</td></tr>";
                    echo "<tr><td>Straight Line (Haversine)</td><td class='val'>" . number_format($haversineKm, 4) . " km</td></tr>";
                    echo "<tr><td><strong>Road-to-Straight Ratio</strong></td><td class='val'>" . number_format($ratio, 2) . "x</td></tr>";
                    echo "</table>";

                    if ($ratio > 5) {
                        echo "<div class='warning'><strong>⚠️ WARNING: Road distance is > 5x straight-line!</strong><br>This is highly suspicious. Check if Mapbox is taking a massive detour or if coordinates are wrong.</div>";
                    } else if ($ratio < 1.0 && $haversineKm > 0.01) {
                        echo "<div class='warning'><strong>🛑 ALERT: Road distance is SHORTER than straight-line!</strong><br>This is mathematically impossible. This almost always indicates that <strong>LATITUDE and LONGITUDE are swapped</strong> in your payload. Mapbox is calculating a route between two different points on the globe than you intended.</div>";
                    }

                    // Integration Check
                    echo "<h2>H) Service Consistency Check</h2>";
                    echo "<p>Running existing <code>getRoadDistanceAndFee()</code> from <code>../../app/services/mapbox_distance.php</code>...</p>";
                    $serviceResult = getRoadDistanceAndFee($uLat, $uLng);
                    echo "<pre>";
                    print_r($serviceResult);
                    echo "</pre>";

                    if ($serviceResult['success']) {
                        $diff = abs($serviceResult['distance_km'] - $distKm);
                        if ($diff > 0.1) {
                            echo "<p class='status-error'>DISCREPANCY: Service function result differs by " . number_format($diff, 2) . " km!</p>";
                        } else {
                            echo "<p class='status-ok'>Service function matches diagnostic results.</p>";
                        }
                    } else {
                        echo "<p class='status-error'>Service Function Failed: " . $serviceResult['error'] . "</p>";
                    }

                } else {
                    echo "<p class='status-error'>No routes found in Mapbox response.</p>";
                    echo "<pre>" . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . "</pre>";
                }
            }
            ?>
        </section>

    <?php else: ?>
        <section style="text-align: center; padding: 50px;">
            <p style="font-size: 1.2em; color: #7f8c8d;">Enter coordinates in the form above to start the diagnostic.</p>
        </section>
    <?php endif; ?>

    <footer style="text-align: center; margin-top: 50px; color: #95a5a6; font-size: 0.85em;">
        &copy;
        <?php echo date('Y'); ?> JustKleek Admin Route Diagnostic Tool | Deep Delivery Verification
    </footer>

</body>

</html>