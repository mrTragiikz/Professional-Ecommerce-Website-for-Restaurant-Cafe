<?php

/**
 * Rider Context (plug-and-play)
 *
 * Central resolver for:
 * - Native rider app (session-based)
 * - Admin impersonation via ?rider_id=XX
 *
 * Usage:
 *   $ctx = RiderContext::resolve();
 *   $riderId = $ctx['riderId'];
 *   $contextMode = $ctx['contextMode']; // 'rider' or 'admin'
 *   $resolverError = $ctx['resolverError']; // null or 'admin_auth_required'
 */
final class RiderContext
{
    public static function resolve(): array
    {
        if (!defined('RiderAppCore')) {
            define('RiderAppCore', true);
        }

        $riderId = 0;
        $contextMode = 'rider';
        $resolverError = null;

        $branchId = 0;
        $requestedRiderId = isset($_GET['rider_id']) ? (int) $_GET['rider_id'] : 0;

        if ($requestedRiderId > 0) {
            $contextMode = 'admin';

            $authPath = __DIR__ . '/../includes/auth.php';
            if (file_exists($authPath)) {
                require_once $authPath;
                if (function_exists('isAdminLoggedIn') && isAdminLoggedIn()) {
                    $riderId = $requestedRiderId;
                    
                    // Fetch branch_id for the impersonated rider
                    require_once __DIR__ . '/../../config/db.php';
                    global $pdo;
                    $stmt = $pdo->prepare("SELECT branch_id FROM riders WHERE id = ?");
                    $stmt->execute([$riderId]);
                    $branchId = (int)$stmt->fetchColumn();
                } else {
                    $resolverError = 'admin_auth_required';
                    $riderId = 0;
                }
            } else {
                $resolverError = 'admin_auth_required';
                $riderId = 0;
            }
        } else {
            require_once __DIR__ . '/_session_init.php';
            $riderId = isset($_SESSION['rider_id']) ? (int) $_SESSION['rider_id'] : 0;
            $branchId = isset($_SESSION['rider_branch_id']) ? (int) $_SESSION['rider_branch_id'] : 0;
            $contextMode = 'rider';
        }

        return [
            'riderId'      => $riderId,
            'branchId'     => $branchId,
            'contextMode'  => $contextMode,
            'resolverError'=> $resolverError,
        ];
    }
}

