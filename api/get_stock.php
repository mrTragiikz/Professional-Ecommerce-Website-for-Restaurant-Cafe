<?php
/**
 * API: Get Stock Status for Frontend
 * Returns an array of items with their stock_count
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allow from any origin if needed, but usually same domain

require_once __DIR__ . '/../config/db.php';

try {
    global $pdo;

    $branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 1;

    // Fetch all items for the specific branch to sync their real-time status
    $stmt = $pdo->prepare("SELECT id, item_name, stock_count, track_stock FROM menu_items WHERE restaurant_id = ?");
    $stmt->execute([$branchId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'stocks' => $items,
        'timestamp' => time()
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error'
    ]);
}
