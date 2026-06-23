<?php
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();

$pageTitle = 'Edit Rider Daily Closing';
require_once __DIR__ . '/includes/header.php';

if (empty($_SESSION['admin_csrf'])) {
    try {
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['admin_csrf'] = md5(uniqid(mt_rand(), true));
    }
}
$csrfToken = $_SESSION['admin_csrf'];

$id = (int) ($_GET['id'] ?? 0);
$error = $_GET['error'] ?? '';

if ($id <= 0) {
    echo "<div class=\"container-fluid\" style=\"padding:20px;\">Invalid closing ID.</div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT c.*, r.full_name AS rider_name, r.phone AS rider_phone
        FROM rider_daily_closings c
        JOIN riders r ON c.rider_id = r.id
        WHERE c.id = ?
    ");
    $stmt->execute([$id]);
    $closing = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $closing = null;
}

if (!$closing) {
    echo "<div class=\"container-fluid\" style=\"padding:20px;\">Closing record not found.</div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

function jk_h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
?>

<div class="container-fluid" style="padding:20px;">
    <h1 style="font-size:22px; font-weight:700; margin-bottom:10px;">
        Edit Closing - <?php echo jk_h($closing['rider_name']); ?> (<?php echo jk_h($closing['closing_date']); ?>)
    </h1>
    <p style="color:#6b7280; margin-bottom:15px;">
        Adjust values only when necessary. All changes are logged with reasons.
    </p>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo jk_h($error); ?></div>
    <?php endif; ?>

    <form method="POST" action="rider_daily_closing_actions.php" class="form-horizontal" style="max-width:640px;">
        <input type="hidden" name="csrf_token" value="<?php echo jk_h($csrfToken); ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="closing_id" value="<?php echo (int) $closing['id']; ?>">

        <div class="form-group">
            <label class="control-label col-sm-3">Start KM</label>
            <div class="col-sm-9">
                <input type="number" name="start_km" step="0.01" min="0" class="form-control"
                    value="<?php echo jk_h($closing['start_km']); ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-sm-3">End KM</label>
            <div class="col-sm-9">
                <input type="number" name="end_km" step="0.01" min="0" class="form-control"
                    value="<?php echo jk_h($closing['end_km']); ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-sm-3">Hired KM</label>
            <div class="col-sm-9">
                <input type="number" name="hired_km" step="0.001" min="0" class="form-control"
                    value="<?php echo jk_h($closing['hired_km']); ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-sm-3">Orders Count</label>
            <div class="col-sm-9">
                <input type="number" name="orders_count" min="0" class="form-control"
                    value="<?php echo jk_h($closing['orders_count']); ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-sm-3">Delivery Revenue (Rs)</label>
            <div class="col-sm-9">
                <input type="number" name="delivery_revenue" step="0.01" min="0" class="form-control"
                    value="<?php echo jk_h($closing['delivery_revenue']); ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-sm-3">Cash Collect (Rs)</label>
            <div class="col-sm-9">
                <input type="number" name="cash_collect" step="0.01" min="0" class="form-control"
                    value="<?php echo jk_h($closing['cash_collect']); ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-sm-3">Online Collect (Rs)</label>
            <div class="col-sm-9">
                <input type="number" name="online_collect" step="0.01" min="0" class="form-control"
                    value="<?php echo jk_h($closing['online_collect']); ?>">
            </div>
        </div>

        <div class="form-group">
            <label class="control-label col-sm-3">Edit Reason</label>
            <div class="col-sm-9">
                <textarea name="reason" rows="3" class="form-control" placeholder="Why are you editing this closing? (required)"></textarea>
                <p class="help-block" style="margin-top:5px; font-size:12px;">
                    Previous reasons / history:<br>
                    <code style="white-space:pre-wrap;"><?php echo jk_h($closing['admin_edit_reason']); ?></code>
                </p>
            </div>
        </div>

        <div class="form-group">
            <div class="col-sm-offset-3 col-sm-9">
                <button type="submit" class="btn btn-primary">Save &amp; Lock</button>
                <a href="rider_daily_closing.php?from=<?php echo jk_h($closing['closing_date']); ?>&to=<?php echo jk_h($closing['closing_date']); ?>"
                    class="btn btn-default">Cancel</a>
            </div>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

