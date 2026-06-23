<?php
/**
 * Security Monitoring Dashboard
 */

require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();

require_once __DIR__ . '/includes/header.php';

$pageTitle = 'Security Monitor';

// Get filter parameters
$filter = $_GET['filter'] ?? 'all';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

global $pdo;

try {
    // Get security events
    $where = [];
    $params = [];

    if ($filter === 'sql_injection') {
        $where[] = "event_type LIKE ?";
        $params[] = '%sql_injection%';
    } elseif ($filter === 'xss') {
        $where[] = "event_type LIKE ?";
        $params[] = '%xss%';
    } elseif ($filter === 'blocked') {
        $where[] = "event_type = ?";
        $params[] = 'ip_blocked';
    } elseif ($filter === 'suspicious') {
        $where[] = "event_type IN (?, ?, ?, ?)";
        $params[] = 'suspicious_bot';
        $params[] = 'rapid_requests';
        $params[] = 'invalid_origin';
        $params[] = 'honeypot_triggered';
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    // Get total count
    $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM security_logs " . $whereClause);
    $countStmt->execute($params);
    $totalEvents = $countStmt->fetch()['total'];
    $totalPages = ceil($totalEvents / $perPage);

    // Get events
    $limitParams = array_merge($params, [$perPage, $offset]);
    $stmt = $pdo->prepare("
        SELECT * FROM security_logs 
        " . $whereClause . "
        ORDER BY created_at DESC 
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($limitParams);
    $events = $stmt->fetchAll();

    // Get blocked IPs
    $blockedStmt = $pdo->prepare("
        SELECT * FROM blocked_ips 
        WHERE blocked_until IS NULL OR blocked_until > NOW()
        ORDER BY created_at DESC
    ");
    $blockedStmt->execute();
    $blockedIPs = $blockedStmt->fetchAll();

    // Get statistics
    $statsStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_events,
            COUNT(DISTINCT ip_address) as unique_ips,
            COUNT(CASE WHEN event_type LIKE '%sql_injection%' THEN 1 END) as sql_attempts,
            COUNT(CASE WHEN event_type LIKE '%xss%' THEN 1 END) as xss_attempts,
            COUNT(CASE WHEN event_type = 'ip_blocked' THEN 1 END) as blocked_count
        FROM security_logs
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $statsStmt->execute();
    $stats = $statsStmt->fetch();

} catch (PDOException $e) {
    error_log("Security monitor error: " . $e->getMessage());
    $events = [];
    $blockedIPs = [];
    $stats = ['total_events' => 0, 'unique_ips' => 0, 'sql_attempts' => 0, 'xss_attempts' => 0, 'blocked_count' => 0];
    $totalPages = 0;
}

// Security logging is disabled as per user request
$isSecurityDisabled = true;
?>

<div class="admin-container">
    <div class="admin-header">
        <h1>Security Monitor</h1>
        <?php if ($isSecurityDisabled): ?>
            <p>Security monitoring has been disabled as requested.</p>
        <?php else: ?>
            <p>View security events, blocked IPs, and system security status</p>
        <?php endif; ?>
    </div>

    <?php if ($isSecurityDisabled): ?>
        <div class="admin-section"
            style="margin-top: 40px; text-align: center; padding: 50px; background: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <div style="font-size: 48px; margin-bottom: 20px;"></div>
            <h2>Security Monitoring Disabled</h2>
            <p style="color: #666; max-width: 600px; margin: 0 auto;">
                The security logging, rate limiting, and IP blocking features have been disabled.
                The system is no longer recording security events or blocking suspicious requests.
            </p>
            <div style="margin-top: 30px;">
                <a href="index.php" class="btn btn-primary">Return to Dashboard</a>
            </div>
        </div>
    <?php else: ?>
        <!-- Filters -->
        <div class="filters" style="margin-bottom: 20px;">
            <a href="?filter=all" class="filter-btn <?php echo $filter === 'all' ? 'active' : ''; ?>">All Events</a>
            <a href="?filter=sql_injection" class="filter-btn <?php echo $filter === 'sql_injection' ? 'active' : ''; ?>">SQL
                Injection</a>
            <a href="?filter=xss" class="filter-btn <?php echo $filter === 'xss' ? 'active' : ''; ?>">XSS</a>
            <a href="?filter=suspicious"
                class="filter-btn <?php echo $filter === 'suspicious' ? 'active' : ''; ?>">Suspicious</a>
            <a href="?filter=blocked" class="filter-btn <?php echo $filter === 'blocked' ? 'active' : ''; ?>">Blocked IPs</a>
        </div>

        <!-- Security Events Table -->
        <div class="admin-table-container">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Event Type</th>
                        <th>IP Address</th>
                        <th>User ID</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($events)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 40px; color: #999;">
                                No security events found
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($event['created_at']); ?></td>
                                <td>
                                    <span class="badge badge-<?php
                                    echo strpos($event['event_type'], 'sql') !== false ? 'danger' :
                                        (strpos($event['event_type'], 'xss') !== false ? 'warning' : 'info');
                                    ?>">
                                        <?php echo htmlspecialchars($event['event_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($event['ip_address']); ?></td>
                                <td><?php echo $event['user_id'] ? htmlspecialchars($event['user_id']) : '-'; ?></td>
                                <td>
                                    <?php
                                    $data = json_decode($event['event_data'], true);
                                    if ($data) {
                                        echo '<small>' . htmlspecialchars(substr(json_encode($data, JSON_PRETTY_PRINT), 0, 200)) . '</small>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?filter=<?php echo $filter; ?>&page=<?php echo $i; ?>"
                        class="page-btn <?php echo $i === $page ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>

        <!-- Blocked IPs Section -->
        <div class="admin-section" style="margin-top: 40px;">
            <h2>Blocked IP Addresses</h2>
            <div class="admin-table-container">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>IP Address</th>
                            <th>Reason</th>
                            <th>Blocked Until</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($blockedIPs)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 20px; color: #999;">
                                    No IPs currently blocked
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($blockedIPs as $blocked): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($blocked['ip_address']); ?></td>
                                    <td><?php echo htmlspecialchars($blocked['reason']); ?></td>
                                    <td><?php echo $blocked['blocked_until'] ? htmlspecialchars($blocked['blocked_until']) : 'Permanent'; ?>
                                    </td>
                                    <td>
                                        <button onclick="unblockIP('<?php echo htmlspecialchars($blocked['ip_address']); ?>')"
                                            class="btn btn-sm btn-success">Unblock</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
            function unblockIP(ip) {
                if (!confirm('Are you sure you want to unblock IP: ' + ip + '?')) {
                    return;
                }

                fetch('api/unblock_ip.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'ip=' + encodeURIComponent(ip)
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            location.reload();
                        } else {
                            alert('Error: ' + (data.error || 'Failed to unblock IP'));
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error unblocking IP');
                    });
            }
        </script>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

