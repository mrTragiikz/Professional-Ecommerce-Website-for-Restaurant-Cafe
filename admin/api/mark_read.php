<?php
/**
 * API: Mark Order as Read
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$orderId = intval($_POST['order_id'] ?? 0);

if (!$orderId) {
    echo json_encode(['success' => false, 'error' => 'Invalid order ID']);
    exit;
}

try {
    global $pdo;
    
    $stmt = $pdo->prepare("UPDATE orders SET is_read = 1 WHERE id = ?");
    $stmt->execute([$orderId]);
    
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("Mark read error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}









