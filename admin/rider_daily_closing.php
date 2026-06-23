<?php
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();

$pageTitle = 'Rider Daily Closing / KM Audit';
require_once __DIR__ . '/includes/header.php';

// CSRF token for admin actions (reuse admin_csrf if present)
if (empty($_SESSION['admin_csrf'])) {
    try {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['admin_csrf'] = md5(uniqid(mt_rand(), true));
    }
}
$csrfToken = $_SESSION['admin_csrf'];

// Filters
$fromDate = $_GET['from'] ?? date('Y-m-d');
$toDate = $_GET['to'] ?? date('Y-m-d');
$riderFilter = isset($_GET['rider_id']) ? (int) $_GET['rider_id'] : 0;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
    $fromDate = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    $toDate = date('Y-m-d');
}

// Fetch riders for filter dropdown
$riders = [];
try {
    $riderStmt = $pdo->query("SELECT id, full_name, phone FROM riders ORDER BY full_name ASC");
    $riders = $riderStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// Fetch closings
$params = [
    ':from' => $fromDate,
    ':to' => $toDate,
];
$where = "c.closing_date BETWEEN :from AND :to";
if ($riderFilter > 0) {
    $where .= " AND c.rider_id = :rider_id";
    $params[':rider_id'] = $riderFilter;
}

$closings = [];
try {
    $sql = "
        SELECT
            c.*,
            r.full_name AS rider_name,
            r.phone AS rider_phone
        FROM rider_daily_closings c
        JOIN riders r ON c.rider_id = r.id
        WHERE $where
        ORDER BY c.closing_date DESC, r.full_name ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $closings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

function jk_h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
?>

<div class="container-fluid" style="padding:20px;">
    <h1 style="font-size:24px; font-weight:700; margin-bottom:16px;">Rider Daily Closing / KM Audit</h1>

    <form method="GET" class="form-inline"
        style="display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin-bottom:20px;">
        <div>
            <label class="control-label">From</label>
            <input type="date" name="from" value="<?php echo jk_h($fromDate); ?>" class="form-control input-sm">
        </div>
        <div>
            <label class="control-label">To</label>
            <input type="date" name="to" value="<?php echo jk_h($toDate); ?>" class="form-control input-sm">
        </div>
        <div>
            <label class="control-label">Rider</label>
            <select name="rider_id" class="form-control input-sm">
                <option value="0">All Riders</option>
                <?php foreach ($riders as $r): ?>
                    <option value="<?php echo (int) $r['id']; ?>" <?php echo $riderFilter === (int) $r['id'] ? 'selected' : ''; ?>>
                        <?php echo jk_h($r['full_name'] . ' (' . $r['phone'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Rider</th>
                    <th>Start KM</th>
                    <th>Duty Started</th>
                    <th>End KM</th>
                    <th>Duty Ended</th>
                    <th>Total KM</th>
                    <th>Hired KM</th>
                    <th>Vacant KM</th>
                    <th>Orders</th>
                    <th>Delivery Revenue</th>
                    <th>Cash</th>
                    <th>Online</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($closings)): ?>
                    <tr>
                        <td colspan="15" style="text-align:center; padding:20px; color:#6b7280;">
                            No closing records found for the selected filters.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($closings as $c): ?>
                        <tr>
                            <td><?php echo jk_h($c['closing_date']); ?></td>
                            <td>
                                <?php echo jk_h($c['rider_name']); ?><br>
                                <small style="color:#6b7280;"><?php echo jk_h($c['rider_phone']); ?></small>
                            </td>
                            <td><?php echo number_format((float) $c['start_km'], 2); ?></td>
                            <td style="white-space:nowrap;"><?php echo $c['duty_start_time'] ? date('d M, h:i A', strtotime($c['duty_start_time'])) : '–'; ?></td>
                            <td><?php echo $c['end_km'] !== null && $c['end_km'] !== '' ? number_format((float) $c['end_km'], 2) : '–'; ?></td>
                            <td style="white-space:nowrap;"><?php echo $c['duty_end_time'] ? date('d M, h:i A', strtotime($c['duty_end_time'])) : '–'; ?></td>
                            <td><?php echo $c['total_km'] !== null && $c['total_km'] !== '' ? number_format((float) $c['total_km'], 2) : '–'; ?></td>
                            <td><?php echo number_format((float) $c['hired_km'], 2); ?></td>
                            <td><?php echo number_format((float) $c['vacant_km'], 2); ?></td>
                            <td><?php echo (int) $c['orders_count']; ?></td>
                            <td>Rs. <?php echo number_format((float) $c['delivery_revenue'], 2); ?></td>
                            <td>Rs. <?php echo number_format((float) $c['cash_collect'], 2); ?></td>
                            <td>Rs. <?php echo number_format((float) $c['online_collect'], 2); ?></td>
                            <td>
                                <?php if ((int) $c['is_locked'] === 1): ?>
                                    <span class="label label-success">Locked</span>
                                <?php else: ?>
                                    <span class="label label-warning">Editable</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $c['is_locked'] === 1): ?>
                                    <form method="POST" action="rider_daily_closing_actions.php" style="display:inline-flex; gap:6px; align-items:center; flex-wrap:wrap;">
                                        <input type="hidden" name="csrf_token" value="<?php echo jk_h($csrfToken); ?>">
                                        <input type="hidden" name="closing_id" value="<?php echo (int) $c['id']; ?>">
                                        <input type="hidden" name="action" value="unlock">
                                        <input type="text" name="reason" placeholder="Reason" required style="max-width:120px;" class="form-control input-sm">
                                        <button type="submit" class="btn btn-xs btn-warning" onclick="return confirm('Unlock for correction?');">Unlock</button>
                                    </form>
                                <?php else: ?>
                                    <a href="rider_daily_closing_edit.php?id=<?php echo (int) $c['id']; ?>" class="btn btn-xs btn-primary">Edit</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

