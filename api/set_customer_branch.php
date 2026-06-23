<?php
require_once __DIR__ . '/../app/functions/security_init.php';
header('Content-Type: application/json');

if (isset($_POST['branch_id'])) {
    $branch_id = (int)$_POST['branch_id'];
    require_once __DIR__ . '/../config/db.php';
    
    // Verify branch exists and is active
    $stmt = $pdo->prepare("SELECT id, name FROM branches WHERE id = ? AND is_active = 1");
    $stmt->execute([$branch_id]);
    $branch = $stmt->fetch();
    
    if ($branch) {
        $_SESSION['customer_branch_id'] = $branch['id'];
        $_SESSION['customer_branch_name'] = $branch['name'];
        echo json_encode(['success' => true, 'name' => $branch['name']]);
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid branch selected']);
