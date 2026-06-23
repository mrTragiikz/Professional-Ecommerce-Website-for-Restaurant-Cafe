<?php
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: rider_daily_closing.php');
    exit;
}

if (empty($_POST['csrf_token']) || empty($_SESSION['admin_csrf']) || $_POST['csrf_token'] !== $_SESSION['admin_csrf']) {
    header('Location: rider_daily_closing.php?error=' . urlencode('Invalid security token.'));
    exit;
}

$action = $_POST['action'] ?? '';
$closingId = (int) ($_POST['closing_id'] ?? 0);

if ($closingId <= 0 || ($action !== 'unlock' && $action !== 'save')) {
    header('Location: rider_daily_closing.php?error=' . urlencode('Invalid request.'));
    exit;
}

try {
    // Load closing
    $stmt = $pdo->prepare("SELECT * FROM rider_daily_closings WHERE id = ?");
    $stmt->execute([$closingId]);
    $closing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$closing) {
        header('Location: rider_daily_closing.php?error=' . urlencode('Closing record not found.'));
        exit;
    }

    if ($action === 'unlock') {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') {
            header('Location: rider_daily_closing.php?error=' . urlencode('Unlock reason is required.'));
            exit;
        }

        $reasonText = sprintf(
            "[%s] UNLOCK by admin_id=%d: %s\n%s",
            date('Y-m-d H:i:s'),
            (int) ($_SESSION['admin_id'] ?? 0),
            $reason,
            (string) ($closing['admin_edit_reason'] ?? '')
        );

        $upd = $pdo->prepare("
            UPDATE rider_daily_closings
            SET is_locked = 0,
                last_admin_id = :admin_id,
                last_admin_edit_at = NOW(),
                admin_edit_reason = :reason
            WHERE id = :id
        ");
        $upd->execute([
            ':admin_id' => (int) ($_SESSION['admin_id'] ?? 0),
            ':reason' => $reasonText,
            ':id' => $closingId,
        ]);

        // Audit log
        try {
            $audit = $pdo->prepare("INSERT INTO rider_audit_logs (rider_id, action, description) VALUES (?, 'closing_unlocked', ?)");
            $desc = sprintf(
                "Closing unlocked for %s (closing_id=%d) by admin_id=%d. Reason: %s",
                $closing['closing_date'],
                $closingId,
                (int) ($_SESSION['admin_id'] ?? 0),
                $reason
            );
            $audit->execute([(int) $closing['rider_id'], $desc]);
        } catch (Exception $e) {
            // non-fatal
        }

        header('Location: rider_daily_closing.php?from=' . urlencode($closing['closing_date']) . '&to=' . urlencode($closing['closing_date']));
        exit;
    }

    if ($action === 'save') {
        // Admin edit & re-lock
        $startKm = (float) ($_POST['start_km'] ?? 0);
        $endKm = (float) ($_POST['end_km'] ?? 0);
        $hiredKm = (float) ($_POST['hired_km'] ?? 0);
        $ordersCount = (int) ($_POST['orders_count'] ?? 0);
        $deliveryRevenue = (float) ($_POST['delivery_revenue'] ?? 0);
        $cashCollect = (float) ($_POST['cash_collect'] ?? 0);
        $onlineCollect = (float) ($_POST['online_collect'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if ($reason === '') {
            header('Location: rider_daily_closing_edit.php?id=' . $closingId . '&error=' . urlencode('Edit reason is required.'));
            exit;
        }
        if ($startKm < 0 || $endKm < 0 || $endKm < $startKm) {
            header('Location: rider_daily_closing_edit.php?id=' . $closingId . '&error=' . urlencode('Please provide valid odometer values.'));
            exit;
        }

        $totalKm = $endKm - $startKm;
        $vacantKm = max($totalKm - $hiredKm, 0);

        $prevSummary = sprintf(
            "OLD start=%.2f end=%.2f total=%.2f hired=%.3f vacant=%.3f orders=%d cash=%.2f online=%.2f",
            $closing['start_km'],
            $closing['end_km'],
            $closing['total_km'],
            $closing['hired_km'],
            $closing['vacant_km'],
            $closing['orders_count'],
            $closing['cash_collect'],
            $closing['online_collect']
        );
        $newSummary = sprintf(
            "NEW start=%.2f end=%.2f total=%.2f hired=%.3f vacant=%.3f orders=%d cash=%.2f online=%.2f",
            $startKm,
            $endKm,
            $totalKm,
            $hiredKm,
            $vacantKm,
            $ordersCount,
            $cashCollect,
            $onlineCollect
        );

        $reasonText = sprintf(
            "[%s] EDIT by admin_id=%d: %s\n%s\n%s\n%s",
            date('Y-m-d H:i:s'),
            (int) ($_SESSION['admin_id'] ?? 0),
            $reason,
            $prevSummary,
            $newSummary,
            (string) ($closing['admin_edit_reason'] ?? '')
        );

        $upd = $pdo->prepare("
            UPDATE rider_daily_closings
            SET start_km = :start_km,
                end_km = :end_km,
                total_km = :total_km,
                hired_km = :hired_km,
                vacant_km = :vacant_km,
                orders_count = :orders_count,
                delivery_revenue = :delivery_revenue,
                cash_collect = :cash_collect,
                online_collect = :online_collect,
                is_locked = 1,
                locked_at = NOW(),
                last_admin_id = :admin_id,
                last_admin_edit_at = NOW(),
                admin_edit_reason = :reason
            WHERE id = :id
        ");
        $upd->execute([
            ':start_km' => $startKm,
            ':end_km' => $endKm,
            ':total_km' => $totalKm,
            ':hired_km' => $hiredKm,
            ':vacant_km' => $vacantKm,
            ':orders_count' => $ordersCount,
            ':delivery_revenue' => $deliveryRevenue,
            ':cash_collect' => $cashCollect,
            ':online_collect' => $onlineCollect,
            ':admin_id' => (int) ($_SESSION['admin_id'] ?? 0),
            ':reason' => $reasonText,
            ':id' => $closingId,
        ]);

        // Audit log
        try {
            $audit = $pdo->prepare("INSERT INTO rider_audit_logs (rider_id, action, description) VALUES (?, 'closing_updated_admin', ?)");
            $desc = sprintf(
                "Closing updated for %s (closing_id=%d) by admin_id=%d. %s | %s",
                $closing['closing_date'],
                $closingId,
                (int) ($_SESSION['admin_id'] ?? 0),
                $prevSummary,
                $newSummary
            );
            $audit->execute([(int) $closing['rider_id'], $desc]);
        } catch (Exception $e) {
            // non-fatal
        }

        header('Location: rider_daily_closing.php?from=' . urlencode($closing['closing_date']) . '&to=' . urlencode($closing['closing_date']));
        exit;
    }
} catch (Exception $e) {
    error_log('Admin rider_daily_closing_actions error: ' . $e->getMessage());
    header('Location: rider_daily_closing.php?error=' . urlencode('Unexpected error.'));
    exit;
}

