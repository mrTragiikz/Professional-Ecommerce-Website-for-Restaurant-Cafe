<?php
/**
 * Unblock IP Address API
 */

require_once __DIR__ . '/../includes/auth.php';
requireAdminLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$ip = $_POST['ip'] ?? '';

if (empty($ip)) {
    echo json_encode(['success' => false, 'error' => 'IP address required']);
    exit;
}

require_once __DIR__ . '/../../config/db.php';

try {
    $stmt = $pdo->prepare("DELETE FROM blocked_ips WHERE ip_address = ?");
    $stmt->execute([$ip]);
    
    require_once __DIR__ . '/../../app/functions/security.php';
    logSecurityEvent('ip_unblocked', [
        'ip' => $ip,
        'admin_id' => $_SESSION['admin_id'] ?? null
    ]);
    
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("Unblock IP error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}





