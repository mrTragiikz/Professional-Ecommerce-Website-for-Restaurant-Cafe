<?php
if (!defined('RiderAppCore')) {
    define('RiderAppCore', true);
}
// Initialize isolated rider session (JK_RIDER_SESS, 7-day lifetime)
require_once __DIR__ . '/_session_init.php';
require_once __DIR__ . '/../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login.php");
    exit;
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['rider_csrf'] ?? null)) {
    $_SESSION['rider_error'] = "Invalid session token. Please try again.";
    header("Location: login.php");
    exit;
}

$identifier = trim($_POST['identifier'] ?? '');
$password = $_POST['password'] ?? '';

if (!$identifier || !$password) {
    $_SESSION['rider_error'] = "Please provide both identifier and password.";
    header("Location: login.php");
    exit;
}

// Authenticate against riders table.
$schema = null;
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM riders");
    if ($stmt) {
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $schema = [
            'table'   => 'riders',
            'id'      => 'id',
            'name'    => in_array('full_name', $cols) ? 'full_name' : (in_array('name', $cols) ? 'name' : 'username'),
            'cols'    => $cols,
            'password'=> in_array('password_hash', $cols) ? 'password_hash' : 'password',
            'status'  => in_array('is_active', $cols) ? 'is_active' : (in_array('status', $cols) ? 'status' : false),
        ];
    }
} catch (Exception $e) {
}

if (!$schema) {
    $_SESSION['rider_error'] = "Riders table not found or misconfigured.";
    header("Location: login.php");
    exit;
}

$params = [];
$identClauses = [];

$hasEmail = in_array('email', $schema['cols']);
$hasPhone = in_array('phone', $schema['cols']);

if ($hasEmail) {
    $identClauses[] = "email = ?";
    $params[] = $identifier;
}
if ($hasPhone) {
    $identClauses[] = "phone = ?";
    $params[] = $identifier;
}

if (empty($identClauses)) {
    $_SESSION['rider_error'] = "Riders table must have email or phone column.";
    header("Location: login.php");
    exit;
}

$whereQuery = "(" . implode(" OR ", $identClauses) . ")";
$tableName = $schema['table'];
$query = "SELECT * FROM {$tableName} WHERE {$whereQuery} LIMIT 1";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    $selectedBranchId = intval($_POST['branch_id'] ?? 0);

    if ($user && password_verify($password, $user[$schema['password']])) {

        // Branch check - Verify rider belongs to selected branch
        if (isset($user['branch_id']) && $user['branch_id'] != $selectedBranchId) {
            $_SESSION['rider_error'] = "Invalid branch! This account does not belong to the selected branch.";
            header("Location: login.php");
            exit;
        }

        // Active status check
        if ($schema['status'] && isset($user[$schema['status']])) {
            $statusVal = strtolower((string) $user[$schema['status']]);
            if ($statusVal == '0' || $statusVal == 'inactive' || $statusVal == 'disabled' || $statusVal == 'false' || $statusVal == 'suspended') {
                $_SESSION['rider_error'] = "Your rider account is disabled. Please contact to administrator.";
                header("Location: login.php");
                exit;
            }
        }

        // Success: bind session directly to riders.id
        session_regenerate_id(true);
        $_SESSION['rider_id'] = $user[$schema['id']];
        $_SESSION['rider_name'] = $user[$schema['name']];
        $_SESSION['rider_branch_id'] = $user['branch_id'] ?? null;
        header("Location: orders.php");
        exit;
    } else {
        $_SESSION['rider_error'] = "Invalid login credentials.";
        header("Location: login.php");
        exit;
    }

} catch (Exception $e) {
    $_SESSION['rider_error'] = "Database error occurred.";
    header("Location: login.php");
    exit;
}
