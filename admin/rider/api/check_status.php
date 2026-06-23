<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../RiderContext.php';

$ctx = RiderContext::resolve();
$riderId = $ctx['riderId'];
$contextMode = $ctx['contextMode'];
$resolverError = $ctx['resolverError'];

if (!$riderId) {
    echo json_encode(['status' => 'inactive']);
    exit;
}


try {
    // Detect status column (same logic as managerider.php)
    $stmt = $pdo->query("SHOW COLUMNS FROM riders");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $statusCol = in_array('is_active', $cols) ? 'is_active' : 'status';

    $stmt = $pdo->prepare("SELECT `$statusCol` FROM riders WHERE id = ?");
    $stmt->execute([$riderId]);
    $statusVal = $stmt->fetchColumn();

    $isActive = (strtolower((string) $statusVal) === 'active' || $statusVal == '1');

    if (!$isActive) {
        $response = ['status' => 'inactive'];
    } else {
        // Fetch count of 'ready' orders that are not yet assigned to any rider
        // Using robust unassigned check (NULL, 0, or Empty String)
        $stmtCount = $pdo->query("SELECT COUNT(*) FROM orders WHERE LOWER(status) = 'ready' AND (rider_id IS NULL OR rider_id = 0 OR rider_id = '')");
        $readyCount = intval($stmtCount->fetchColumn());

        $response = [
            'status' => 'active',
            'ready_orders_count' => $readyCount
        ];
    }
    echo json_encode($response);
} catch (Exception $e) {
    echo json_encode(['status' => 'unknown', 'ready_orders_count' => 0]);
}
