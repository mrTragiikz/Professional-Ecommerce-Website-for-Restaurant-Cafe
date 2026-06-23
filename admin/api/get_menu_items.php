<?php
/**
 * API: Get Menu Items for Manual Order
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../config/db.php';

try {
    global $pdo;

    $branchId = getAdminBranchId(); // null means "all branches" (super admin)

    if ($branchId) {
        $stmt = $pdo->prepare("
            SELECT m.id, m.item_name as name, m.price, m.item_description as description, m.image_path as image, c.category_name as category
            FROM menu_items m
            LEFT JOIN menu_categories c ON m.category_id = c.id
            WHERE m.is_active = 1 AND m.restaurant_id = ?
            ORDER BY c.display_order ASC, m.item_name ASC
        ");
        $stmt->execute([$branchId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Fetch across all branches (super admin mode)
        $stmt = $pdo->query("
            SELECT m.id, m.item_name as name, m.price, m.item_description as description, m.image_path as image, c.category_name as category
            FROM menu_items m
            LEFT JOIN menu_categories c ON m.category_id = c.id
            WHERE m.is_active = 1
            ORDER BY c.display_order ASC, m.item_name ASC
        ");
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['success' => true, 'items' => $items]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
