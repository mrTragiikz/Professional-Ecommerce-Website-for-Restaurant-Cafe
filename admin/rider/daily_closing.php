<?php
if (!defined('RiderAppCore')) {
    define('RiderAppCore', true);
}
require __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../config/db.php';
require __DIR__ . '/_header.php';

$riderName = htmlspecialchars($_SESSION['rider_name'] ?? 'Rider');
?>
<script>
    const currentRiderId = <?php echo json_encode($riderId); ?>;
    const currentContextMode = <?php echo json_encode($contextMode); ?>;
</script>
<?php


if (empty($_SESSION['rider_csrf'])) {
    $_SESSION['rider_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['rider_csrf'];

// Strict Time Enforcement
date_default_timezone_set('Asia/Kathmandu');
$now = time();
$currentHour = (int) date('G', $now);
$todayDate = date('Y-m-d', $now);
$yesterdayDate = date('Y-m-d', strtotime('-1 day', $now));
$tomorrowDate = date('Y-m-d', strtotime('+1 day', $now));

// Determine Business Date & Window Status
if ($currentHour >= 10) {
    $workingDate = $todayDate;
    $portalStatus = 'ACTIVE';
    $isLockedWindow = false;
} elseif ($currentHour >= 4) {
    // 04:00 AM -> 09:59:59 AM
    $workingDate = $todayDate; // Show the NEW date, waiting for 10 AM to start duty
    $portalStatus = 'CLOSED';
    $isLockedWindow = true;
} else {
    // 12:00 AM -> 03:59:59 AM
    $workingDate = $yesterdayDate; // Still working on yesterday's duty cycle
    $portalStatus = 'ACTIVE';
    $isLockedWindow = false;
}

// -------------------------------------------------------------
// Auto-Heartbeat Logic
// -------------------------------------------------------------

// 1. Auto-Finalize Past / Missing End KMs
$stmtOpen = $pdo->prepare("SELECT id, start_km, end_km, closing_date FROM rider_daily_closings WHERE rider_id = ? AND (is_locked = 0 OR is_locked IS NULL)");
$stmtOpen->execute([$riderId]);
$openRecs = $stmtOpen->fetchAll(PDO::FETCH_ASSOC);

foreach ($openRecs as $rec) {
    $recDate = $rec['closing_date'];

    // Automatically finalize any record from a past business cycle.
    // At exactly 4 AM, $workingDate jumps to $todayDate, so yesterday's shift becomes strictly < $workingDate.
    if ($recDate < $workingDate) {
        $stKm = $rec['start_km'];
        $enKm = $rec['end_km'];
        $status = ($enKm !== null) ? 'COMPLETED' : 'MISSING_END_KM';

        $totalKmUpdate = "";
        $params = [];
        if ($enKm !== null && $stKm !== null) {
            $totalKmUpdate = ", total_km = ?";
            $params[] = (float) $enKm - (float) $stKm;
        }

        $params[] = $status;
        $params[] = $rec['id'];

        try {
            $pdo->prepare("UPDATE rider_daily_closings SET is_locked = 1, locked_at = NOW(), finalized_at = NOW() $totalKmUpdate, status = ? WHERE id = ?")
                ->execute($params);
        } catch (PDOException $e) {
            try {
                // Fallback without status or finalized_at if schema lacks them
                $pdo->prepare("UPDATE rider_daily_closings SET is_locked = 1, locked_at = NOW() $totalKmUpdate WHERE id = ?")
                    ->execute(array_diff($params, [$status]));
            } catch (PDOException $ex) {
            }
        }
    }
}

// 2. Auto-Create Today's Row if 10 AM reached
if ($currentHour >= 10) {
    $stmtCheck = $pdo->prepare("SELECT id FROM rider_daily_closings WHERE rider_id = ? AND closing_date = ?");
    $stmtCheck->execute([$riderId, $workingDate]);
    if (!$stmtCheck->fetch()) {
        try {
            $pdo->prepare("INSERT INTO rider_daily_closings (rider_id, closing_date, start_km, end_km, is_locked, status, created_by_rider_id) VALUES (?, ?, NULL, NULL, 0, 'OPEN', ?)")
                ->execute([$riderId, $workingDate, $riderId]);
        } catch (PDOException $e) {
            try {
                // Fallback if missing status column
                $pdo->prepare("INSERT INTO rider_daily_closings (rider_id, closing_date, start_km, end_km, is_locked, created_by_rider_id) VALUES (?, ?, NULL, NULL, 0, ?)")
                    ->execute([$riderId, $workingDate, $riderId]);
            } catch (PDOException $ex) {
            }
        }
    }
}
// -------------------------------------------------------------

$recordDate = $workingDate;
$existingClosing = null;

try {
    $stmt = $pdo->prepare("SELECT * FROM rider_daily_closings WHERE rider_id = ? AND closing_date = ?");
    $stmt->execute([$riderId, $workingDate]);
    $existingClosing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // If not found (e.g., active window before 10 AM working on yesterday), attempt to fetch latest available
    if (!$existingClosing) {
        $stmtFallback = $pdo->prepare("SELECT * FROM rider_daily_closings WHERE rider_id = ? AND (is_locked = 0 OR is_locked IS NULL) ORDER BY closing_date DESC LIMIT 1");
        $stmtFallback->execute([$riderId]);
        $existingClosing = $stmtFallback->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existingClosing) {
            $recordDate = $existingClosing['closing_date'];
        }
    } else {
        $recordDate = $existingClosing['closing_date'];
    }
} catch (Exception $e) {
    $error = 'Unable to load daily closing data.';
}

function jk_h($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

$isLocked = $existingClosing && (int) ($existingClosing['is_locked'] ?? 0) === 1;
// Consider Start KM valid only if it's not strictly null. It might be 0.00
$hasStart = $existingClosing && isset($existingClosing['start_km']) && $existingClosing['start_km'] !== '' && $existingClosing['start_km'] !== null;
$hasEnd = $existingClosing && isset($existingClosing['end_km']) && $existingClosing['end_km'] !== '' && $existingClosing['end_km'] !== null;

// Determine Current Step
$step = 'start';
if ($isLocked) {
    $step = 'done';
} elseif ($hasStart && $hasEnd) {
    $step = 'stop';
} elseif ($hasStart) {
    $step = 'end';
}

$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';
?>
<?php $rider_current_page = 'daily_closing';
require __DIR__ . '/_sidebar.php'; ?>
<!-- Professional Topbar -->
<div class="rider-topbar">
    <div class="hamburger-btn" onclick="toggleSidebar()">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
            stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
        </svg>
    </div>

    <div class="topbar-left" style="display:flex; align-items:center; gap:12px;">
        <div class="logo-icon" style="width:32px; height:32px; box-shadow:none;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path>
            </svg>
        </div>
        <span class="topbar-title">JustKleek</span>
    </div>

    <!-- Desktop Navigation (only shown on PC) -->
    <div class="rider-desktop-nav">
        <a href="daily_closing.php" class="desktop-link active">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
            Bike Km Set
        </a>
        <a href="orders.php" class="desktop-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="1" y="3" width="15" height="13"></rect>
                <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
                <circle cx="5.5" cy="18.5" r="2.5"></circle>
                <circle cx="18.5" cy="18.5" r="2.5"></circle>
            </svg>
            Delivery
        </a>
        <a href="orders.php?filter=delivered" class="desktop-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
            </svg>
            Completed
        </a>
        <a href="my_details.php" class="desktop-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                <circle cx="12" cy="7" r="4"></circle>
            </svg>
            My Details
        </a>
        <a href="logout.php" class="desktop-link logout">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
            Sign Out
        </a>
    </div>

    <div style="flex:0; width:40px;" class="mobile-spacer"></div>
</div>

<div class="rider-container"
    style="justify-content:flex-start; padding-top: 20px; flex-direction:column; align-items:center;">

    <!-- LIVE TIME & STATUS PANEL -->
    <div class="rider-card"
        style="max-width: 560px; width:100%; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #ffffff; padding: 22px; margin-bottom: 24px; position: relative; overflow: hidden; border: none; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
        <!-- decorative background blur -->
        <div
            style="position: absolute; top: -50px; left: -50px; width: 140px; height: 140px; background: rgba(56, 189, 248, 0.12); filter: blur(40px); border-radius: 50%;">
        </div>

        <div style="position: relative; z-index: 1;">
            <!-- Top row: Status Badge & Time -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px;">
                <div style="display: flex; flex-direction: column;">
                    <span
                        style="font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; font-weight: 600;">Current
                        Time (Nepal)</span>
                    <span id="live-current-time"
                        style="font-size: 1.6rem; font-weight: 700; color: #f8fafc; font-variant-numeric: tabular-nums; letter-spacing: -0.5px;">--:--:--
                        --</span>
                </div>
                <div id="live-portal-status"
                    style="padding: 6px 14px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; background: rgba(255, 255, 255, 0.1); color: #fff; border: 1px solid rgba(255, 255, 255, 0.1);">
                    ...
                </div>
            </div>

            <!-- Bottom row: Shift Info -->
            <div
                style="background: rgba(0, 0, 0, 0.2); border-radius: 12px; padding: 14px 16px; border: 1px solid rgba(255, 255, 255, 0.05); display: flex; justify-content: space-between; align-items: center;">
                <div style="display: flex; flex-direction: column; gap: 8px; font-size: 0.85rem; color: #94a3b8;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color:#4ade80;">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                        Opens: <strong style="color: #f1f5f9; font-weight: 600;">10:00 AM</strong>
                    </div>
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color:#f87171;">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        Closes: <strong style="color: #f1f5f9; font-weight: 600;">04:00 AM</strong>
                    </div>
                </div>

                <div style="text-align: right; display: flex; flex-direction: column; align-items: flex-end;">
                    <span id="live-countdown-label"
                        style="font-size: 0.7rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; font-weight: 600;">...</span>
                    <span id="live-countdown"
                        style="font-size: 1.25rem; font-weight: 700; color: #fbbf24; font-variant-numeric: tabular-nums; letter-spacing: 0.5px; text-shadow: 0 2px 10px rgba(251, 191, 36, 0.15);">--:--:--</span>
                </div>
            </div>
        </div>
    </div>

    <!-- RIDER MECHANICS NOTICE -->
    <div
        style="max-width: 560px; width:100%; margin-bottom: 24px; padding: 14px 18px; background: #f0f9ff; border-left: 4px solid #38bdf8; border-radius: 8px; font-size: 0.85rem; color: #334155; line-height: 1.6; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <strong
            style="color: #0284c7; display: flex; align-items: center; gap: 6px; margin-bottom: 6px; font-size: 0.9rem;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="16" x2="12" y2="12"></line>
                <line x1="12" y1="8" x2="12.01" y2="8"></line>
            </svg>
            How Your Shift Works
        </strong>
        <div style="display: grid; gap: 4px;">
            <div>• Duty officially starts at <strong>10:00 AM</strong>.</div>
            <div>• Duty officially ends at <strong>04:00 AM</strong> the next morning.</div>
            <div>• Anything you deliver past midnight (12 AM) still counts towards <strong>yesterday's</strong> shift.
            </div>
            <div>• At exactly 4:00 AM, the shift is permanently finalized and locked.</div>
        </div>
    </div>

    <div class="rider-card" style="max-width: 560px; width:100%;">
        <h2 class="rider-title"
            style="text-align:left; margin-bottom: 8px; display:flex; align-items:center; gap:10px;">
            <?php echo $riderName; ?>
            <span
                style="font-size:0.85rem; background:#f1f5f9; color:#475569; padding:4px 12px; border-radius:20px; font-weight:600; border:1px solid #e2e8f0; display:inline-flex; align-items:center; letter-spacing:0.5px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                    stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>
                <?php echo date('d M, Y', strtotime($workingDate)); ?>
            </span>
        </h2>
        <p style="color:var(--text-muted); margin-bottom: 16px; font-size:0.9rem;">
            Daily Mileage: Start KM and End KM only. Confirm each step; no edit after confirm.
        </p>

        <?php if ($msg === 'submitted'): ?>
            <div class="rider-alert" style="background:#ecfdf3; color:#166534; border-color:#bbf7d0;">
                Day set and locked for <?php echo jk_h($recordDate); ?>.
            </div>
        <?php elseif ($msg === 'start_saved'): ?>
            <div class="rider-alert" style="background:#ecfdf3; color:#166534;">Start KM saved. Enter End KM when you
                finish.</div>
        <?php elseif ($msg === 'end_saved'): ?>
            <div class="rider-alert" style="background:#ecfdf3; color:#166534;">End KM saved. Click Stop Duty to lock the
                day.</div>
        <?php elseif ($error): ?>
            <div class="rider-alert" style="background:#fef2f2; color:#991b1b;"><?php echo jk_h($error); ?></div>
        <?php endif; ?>

        <div class="rider-audit-block"
            style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:20px;">
            <div class="rider-form-group" style="margin-bottom:12px;">
                <label class="rider-label">Start KM (morning)</label>
                <div class="rider-audit-value" id="audit-start">
                    <?php echo $hasStart ? jk_h(number_format((float) $existingClosing['start_km'], 2)) : '–'; ?>
                </div>
            </div>
            <div class="rider-form-group" style="margin-bottom:12px;">
                <label class="rider-label">Duty Started</label>
                <div class="rider-audit-value" style="font-size:1rem; color:#64748b;">
                    <?php echo ($existingClosing && $existingClosing['duty_start_time']) ? date('d M, h:i A', strtotime($existingClosing['duty_start_time'])) : '–'; ?>
                </div>
            </div>
            <div class="rider-form-group" style="margin-bottom:12px;">
                <label class="rider-label">End KM (night)</label>
                <div class="rider-audit-value" id="audit-end">
                    <?php echo $hasEnd ? jk_h(number_format((float) $existingClosing['end_km'], 2)) : '–'; ?>
                </div>
            </div>
            <div class="rider-form-group" style="margin-bottom:12px;">
                <label class="rider-label">Duty Ended</label>
                <div class="rider-audit-value" style="font-size:1rem; color:#64748b;">
                    <?php echo ($existingClosing && $existingClosing['duty_end_time']) ? date('d M, h:i A', strtotime($existingClosing['duty_end_time'])) : '–'; ?>
                </div>
            </div>
            <div class="rider-form-group">
                <label class="rider-label">Total Bike KM</label>
                <div class="rider-audit-value" id="audit-total">
                    <?php
                    if ($hasStart && $hasEnd && $existingClosing['total_km'] !== null) {
                        echo jk_h(number_format((float) $existingClosing['total_km'], 2));
                    } else {
                        echo '–';
                    }
                    ?>
                </div>
            </div>
        </div>

        <?php if ($isLockedWindow): ?>
            <div class="rider-alert" style="background:#fef2f2; color:#991b1b; border-color:#fee2e2;">
                <strong>Shift closed.</strong> Next shift opens at 10:00 AM.
            </div>
            <p style="font-size:0.9rem; color:var(--text-muted); margin-bottom: 12px;">Portal operations are locked between
                4:00 AM and 10:00 AM.</p>
        <?php else: ?>
            <?php if ($step === 'start'): ?>
                <button type="button" class="rider-btn" id="start-duty-btn" style="width:100%;" onclick="openStartModal()">Start
                    Duty</button>
                <p id="start-duty-hint" style="margin-top:12px; font-size:0.85rem; color:var(--text-muted);">Click to enter
                    morning odometer and confirm. You cannot edit after confirm.</p>
            <?php elseif ($step === 'end'): ?>
                <button type="button" class="rider-btn" style="width:100%;" onclick="openEndModal()">Enter End KM &amp;
                    Confirm</button>
                <p style="margin-top:12px; font-size:0.85rem; color:var(--text-muted);">Enter night odometer and confirm. Then
                    use Stop Duty to lock the day.</p>
            <?php elseif ($step === 'stop'): ?>
                <form action="daily_closing_actions.php" method="POST" style="margin-top:0;">
                    <input type="hidden" name="csrf_token" value="<?php echo jk_h($csrfToken); ?>">
                    <input type="hidden" name="action" value="stop_duty">
                    <input type="hidden" name="closing_date" value="<?php echo jk_h($workingDate); ?>">
                    <?php if (isset($contextMode) && $contextMode === 'admin'): ?>
                        <input type="hidden" name="rider_id" value="<?php echo $riderId; ?>">
                    <?php endif; ?>

                    <button type="submit" class="rider-btn" style="width:100%;">Stop Duty – Lock Day</button>
                </form>
                <p style="margin-top:12px; font-size:0.85rem; color:var(--text-muted);">After this, the day is audited and
                    locked. Contact admin for any change.</p>
            <?php else: ?>
                <p style="font-size:0.9rem; color:#16a34a; margin-bottom: 12px;">This day is audited and locked. Contact admin
                    for corrections.</p>
                <div
                    style="padding: 12px; border: 1px dashed #cbd5e1; border-radius: 8px; background: #f8fafc; font-size: 0.85rem; color: #475569;">
                    <strong>Next Duty Start:</strong> Wait until 10:00 AM for the new business day.
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Start KM modal -->
<div id="startModal" class="rider-modal-overlay" style="display:none;">
    <div class="rider-modal-content">
        <div class="rider-modal-header">
            <h3 style="margin:0;">Start Duty – Enter Start KM</h3>
            <button type="button" onclick="closeStartModal()"
                style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <form action="daily_closing_actions.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo jk_h($csrfToken); ?>">
            <input type="hidden" name="action" value="start_duty">
            <input type="hidden" name="closing_date" value="<?php echo jk_h($workingDate); ?>">
            <?php if (isset($contextMode) && $contextMode === 'admin'): ?>
                <input type="hidden" name="rider_id" value="<?php echo $riderId; ?>">
            <?php endif; ?>

            <div class="rider-form-group">
                <label class="rider-label">Start KM (morning)</label>
                <input type="number" name="start_km" id="modal_start_km" class="rider-input" min="0" step="0.01"
                    required>
            </div>
            <p style="font-size:0.85rem; color:var(--text-muted); margin-bottom:12px;">After confirm you cannot edit
                this value.</p>
            <button type="submit" class="rider-btn" style="width:100%;">Confirm</button>
        </form>
    </div>
</div>

<!-- End KM modal -->
<div id="endModal" class="rider-modal-overlay" style="display:none;">
    <div class="rider-modal-content">
        <div class="rider-modal-header">
            <h3 style="margin:0;">Enter End KM</h3>
            <button type="button" onclick="closeEndModal()"
                style="background:none; border:none; font-size:1.5rem; cursor:pointer;">&times;</button>
        </div>
        <form action="daily_closing_actions.php" method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo jk_h($csrfToken); ?>">
            <input type="hidden" name="action" value="end_km">
            <input type="hidden" name="closing_date" value="<?php echo jk_h($workingDate); ?>">
            <?php if (isset($contextMode) && $contextMode === 'admin'): ?>
                <input type="hidden" name="rider_id" value="<?php echo $riderId; ?>">
            <?php endif; ?>

            <div class="rider-form-group">
                <label class="rider-label">End KM (night)</label>
                <input type="number" name="end_km" id="modal_end_km" class="rider-input" min="0" step="0.01" required>
            </div>
            <p style="font-size:0.85rem; color:var(--text-muted); margin-bottom:12px;">Must be &ge; Start KM. After
                confirm you cannot edit.</p>
            <button type="submit" class="rider-btn" style="width:100%;">Confirm</button>
        </form>
    </div>
</div>

<style>
    .rider-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.4);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 9999;
        padding: 20px;
    }

    .rider-modal-content {
        background: #fff;
        border-radius: 12px;
        padding: 24px;
        max-width: 400px;
        width: 100%;
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
    }

    .rider-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
    }

    .rider-audit-value {
        font-size: 1.25rem;
        font-weight: 700;
        color: #1e293b;
    }
</style>

<script>
    function openStartModal() {
        var m = document.getElementById('startModal');
        var inp = document.getElementById('modal_start_km');
        if (m) m.style.display = 'flex';
        if (inp) inp.focus();
        document.body.style.overflow = 'hidden';
    }
    function closeStartModal() {
        var m = document.getElementById('startModal');
        if (m) m.style.display = 'none';
        document.body.style.overflow = '';
    }
    function openEndModal() {
        var m = document.getElementById('endModal');
        var inp = document.getElementById('modal_end_km');
        if (m) m.style.display = 'flex';
        if (inp) inp.focus();
        document.body.style.overflow = 'hidden';
    }
    function closeEndModal() {
        var m = document.getElementById('endModal');
        if (m) m.style.display = 'none';
        document.body.style.overflow = '';
    }
    var startModal = document.getElementById('startModal');
    var endModal = document.getElementById('endModal');
    if (startModal) startModal.addEventListener('click', function (e) { if (e.target === this) closeStartModal(); });
    if (endModal) endModal.addEventListener('click', function (e) { if (e.target === this) closeEndModal(); });

    // LIVE Countdowns and Clock
    const serverTimeMs = <?php echo time() * 1000; ?>;
    const localStartTimeMs = Date.now();

    function updateTimePanel() {
        const nowStamp = serverTimeMs + (Date.now() - localStartTimeMs);
        const d = new Date(nowStamp);

        // Nepal offset is +5:45 (345 minutes)
        const ktmMs = d.getTime() + (d.getTimezoneOffset() * 60000) + (345 * 60000);
        const ktmDate = new Date(ktmMs);

        let hours = ktmDate.getHours();
        let mins = ktmDate.getMinutes();
        let secs = ktmDate.getSeconds();

        let ampm = hours >= 12 ? 'PM' : 'AM';
        let h12 = hours % 12;
        if (h12 === 0) h12 = 12;

        let timeStr = h12 + ":" + String(mins).padStart(2, '0') + ":" + String(secs).padStart(2, '0') + " " + ampm;
        document.getElementById('live-current-time').innerText = timeStr;

        let isLocked = (hours >= 4 && hours < 10);
        let statusEl = document.getElementById('live-portal-status');
        if (isLocked) {
            statusEl.innerHTML = "CLOSED";
            statusEl.style.background = "rgba(239, 68, 68, 0.15)";
            statusEl.style.color = "#fca5a5";
            statusEl.style.borderColor = "rgba(239, 68, 68, 0.3)";
        } else {
            statusEl.innerHTML = "ACTIVE";
            statusEl.style.background = "rgba(34, 197, 94, 0.15)";
            statusEl.style.color = "#86efac";
            statusEl.style.borderColor = "rgba(34, 197, 94, 0.3)";
        }

        let targetDate = new Date(ktmDate);
        targetDate.setMinutes(0);
        targetDate.setSeconds(0);
        targetDate.setMilliseconds(0);

        let countdownLabel = "";
        if (hours >= 10) {
            targetDate.setDate(targetDate.getDate() + 1);
            targetDate.setHours(4);
            countdownLabel = "Shift Ends In";
        } else if (hours >= 4) {
            targetDate.setHours(10);
            countdownLabel = "Next Duty Starts In";
        } else {
            targetDate.setHours(4);
            countdownLabel = "Shift Ends In";
        }

        let diffSecs = Math.floor((targetDate.getTime() - ktmDate.getTime()) / 1000);
        let remH = Math.floor(diffSecs / 3600);
        let remM = Math.floor((diffSecs % 3600) / 60);
        let remS = diffSecs % 60;

        document.getElementById('live-countdown-label').innerText = countdownLabel;
        document.getElementById('live-countdown').innerText =
            String(remH).padStart(2, '0') + ":" +
            String(remM).padStart(2, '0') + ":" +
            String(remS).padStart(2, '0');

        // Check window changes live & reload
        let serverLockedStr = "<?php echo $isLockedWindow ? 'true' : 'false'; ?>";
        let serverWasLocked = serverLockedStr === 'true';
        if (isLocked !== serverWasLocked) {
            setTimeout(() => location.reload(), 1500); // Reload the portal to update states
        }
    }

    // Check every second for UI sync
    setInterval(updateTimePanel, 1000);
    updateTimePanel();
</script>

<?php require __DIR__ . '/_footer.php'; ?>