<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$pin = $data['pin'] ?? '';
$adminId = $_SESSION['admin_id'];

// 1. Initialize attempts if not set
if (!isset($_SESSION['pin_attempts'])) {
    $_SESSION['pin_attempts'] = 0;
}

// 2. CHECK LOCKOUT FIRST (If already at 5, don't even check the PIN)
if ($_SESSION['pin_attempts'] >= 5) {
    echo json_encode([
        'success' => false, 
        'message' => 'Security Lockout: Maximum attempts exceeded. Use "Forgot PIN" to reset.',
        'locked' => true
    ]);
    exit;
}

// 3. Fetch current PIN from DB
try {
    global $pdo;
    $stmt = $pdo->prepare("SELECT pin_code, username FROM admins WHERE id = ?");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch();
    $masterPin = $admin['pin_code'] ?? '1234';
} catch (Exception $e) {
    $masterPin = '1234';
}

// 4. VERIFY PIN
if ($pin === $masterPin) {
    $_SESSION['admin_pin_verified'] = true;
    $_SESSION['pin_attempts'] = 0; // Reset attempts on success
    echo json_encode(['success' => true]);
} else {
    // Increment failures
    $_SESSION['pin_attempts']++;
    $count = $_SESSION['pin_attempts'];
    $remaining = 5 - $count;
    
    // Synthetic delay for brute force protection
    if ($count >= 3) {
        usleep(500000); 
    }

    if ($count >= 5) {
        // LOCKOUT REACHED - Send Alert Email
        require_once __DIR__ . '/../../app/functions/email.php';
        $emailConfig = getEmailConfig();
        $adminEmail = $emailConfig['admin_email'] ?? 'info@justkleek.com';
        sendAdminSecurityAlertEmail($adminEmail, $count);
        
        $message = 'Security Alert: Maximum attempts exceeded. Admin has been notified.';
    } else if ($count >= 3) {
        $message = "Incorrect PIN. Remaining attempts: {$remaining}";
    } else {
        $message = 'Incorrect PIN. Try again.';
    }

    echo json_encode([
        'success' => false,
        'message' => $message,
        'attempts' => $count,
        'remaining' => max(0, $remaining)
    ]);
}
