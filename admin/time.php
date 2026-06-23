<?php
/**
 * Time Selection - Manage Restaurant Operating Hours
 */

require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
requireAdminPin();

$pageTitle = 'Time Selection';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../config/load_security.php';

$secureDir = getSecureConfigPath();
$current_branch_id = getAdminBranchId();

// Super admin might be in "All branches" mode. Time settings must be edited per-branch.
if (!$current_branch_id) {
    echo '<div style="max-width: 720px; margin: 40px auto; padding: 0 20px;">';
    echo '<div style="background:#fff7ed; border:1px solid #fed7aa; color:#9a3412; padding:16px 18px; border-radius:14px; font-weight:700;">';
    echo 'Please select a branch first (top-right branch selector) to manage operating hours.';
    echo '</div></div>';
    require_once __DIR__ . '/includes/footer.php';
    exit;
}
$settingsFile = $secureDir . DIRECTORY_SEPARATOR . 'restaurant_hours_branch_' . intval($current_branch_id) . '.json';

// Initial settings load
$settings = getRestaurantSettings($current_branch_id);
$message = '';
$messageType = '';
// POST handling removed - handled via api/save_time.php
?>

<link rel="preconnect" href="https://fonts.bunny.net">
<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
<link
    href="https://fonts.bunny.net/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Poppins:wght@400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap"
    rel="stylesheet">

<style>
    :root {
        --font-family: 'Plus Jakarta Sans', sans-serif;
        --color-bg: #f8fafc;
        --color-card: #ffffff;
        --color-text-main: #0f172a;
        --color-text-muted: #64748b;
        --color-border: #e2e8f0;
        --color-primary: #6366f1;
        --color-primary-hover: #4f46e5;
        --color-success-bg: #f0fdf4;
        --color-success-text: #166534;
        --color-danger-bg: #fef2f2;
        --color-danger-text: #991b1b;
        --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }

    body {
        font-family: var(--font-family) !important;
        background-color: var(--color-bg);
        color: var(--color-text-main);
    }

    .live-status-banner {
        background: #1e293b;
        color: white;
        padding: 24px;
        border-radius: 16px;
        margin-bottom: 32px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-shadow: var(--shadow-md);
    }

    .live-time-group {
        display: flex;
        flex-direction: column;
    }

    .live-time-label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: 1px;
        opacity: 0.7;
        margin-bottom: 4px;
    }

    .live-time-value {
        font-size: 28px;
        font-weight: 700;
        font-family: 'Poppins', sans-serif;
        display: flex;
        align-items: baseline;
        gap: 8px;
    }

    .live-status-details {
        font-size: 12px;
        opacity: 0.8;
        font-weight: 400;
    }

    .live-status-badge {
        padding: 8px 20px;
        border-radius: 100px;
        font-weight: 700;
        font-size: 14px;
        text-transform: uppercase;
    }

    .status-open {
        background: #10b981;
        color: white;
    }

    .status-closed {
        background: #ef4444;
        color: white;
    }

    .time-picker-custom {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .time-picker-custom select {
        width: 80px;
        text-align: center;
        padding-right: 12px;
        background-position: right 8px center;
        background-repeat: no-repeat;
        border: 1px solid var(--color-border);
        border-radius: 10px;
        padding: 12px 16px;
        appearance: none;
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
        background-size: 20px 20px;
        padding-right: 40px;
    }

    .time-picker-custom select:focus {
        outline: none;
        border-color: var(--color-primary);
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
    }

    .time-picker-period {
        width: 90px !important;
    }

    .settings-container {
        max-width: 720px;
        margin: 40px auto;
        padding: 0 20px;
    }

    .settings-header {
        margin-bottom: 32px;
    }

    .page-subtitle {
        font-size: 15px;
        color: var(--color-text-muted);
        margin-top: 8px;
    }

    .settings-card {
        background: var(--color-card);
        border: 1px solid var(--color-border);
        border-radius: 16px;
        box-shadow: var(--shadow-sm);
        padding: 32px;
    }

    .form-group {
        margin-bottom: 28px;
    }

    .form-label {
        display: block;
        font-size: 14px;
        font-weight: 600;
        color: var(--color-text-main);
        margin-bottom: 8px;
    }

    .form-description {
        font-size: 13px;
        color: var(--color-text-muted);
        margin-bottom: 12px;
        line-height: 1.5;
    }

    .custom-input-wrapper {
        position: relative;
    }

    .custom-input {
        width: 100%;
        padding: 12px 16px;
        font-family: var(--font-family);
        font-size: 15px;
        color: var(--color-text-main);
        background-color: #fff;
        border: 1px solid var(--color-border);
        border-radius: 10px;
        transition: all 0.2s ease;
    }

    .time-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
    }

    .info-box {
        background: #f8fafc;
        border: 1px solid var(--color-border);
        border-radius: 12px;
        padding: 16px;
        margin-top: 32px;
        display: flex;
        gap: 16px;
    }

    .info-icon {
        color: var(--color-primary);
        font-size: 20px;
        flex-shrink: 0;
    }

    .info-content h4 {
        margin: 0 0 4px 0;
        font-size: 14px;
        font-weight: 600;
        color: var(--color-text-main);
    }

    .info-content p {
        margin: 0;
        font-size: 13px;
        color: var(--color-text-muted);
        line-height: 1.5;
    }

    .btn-edit {
        background-color: #f1f5f9;
        color: var(--color-text-main);
        border: 1px solid var(--color-border);
        padding: 12px 32px;
        border-radius: 10px;
        font-family: var(--font-family);
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .btn-edit:hover {
        background-color: #e2e8f0;
    }

    .custom-select:disabled, .switch input:disabled {
        background-color: #f8fafc;
        cursor: not-allowed;
        opacity: 0.6;
    }

    .btn-save {
        background-color: var(--color-text-main);
        color: white;
        border: none;
        padding: 12px 32px;
        border-radius: 10px;
        font-family: var(--font-family);
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }

    .btn-save:hover {
        background-color: #000;
        transform: translateY(-1px);
        box-shadow: 0 6px 8px -1px rgba(0, 0, 0, 0.15);
    }

    .btn-save:disabled {
        opacity: 0.7;
        cursor: not-allowed;
        transform: none;
    }

    .loading-spinner {
        display: inline-block;
        width: 14px;
        height: 14px;
        border: 2px solid rgba(255, 255, 255, .3);
        border-radius: 50%;
        border-top-color: #fff;
        animation: spin 1s ease-in-out infinite;
        margin-right: 8px;
    }

    @keyframes spin {
        to {
            transform: rotate(360deg);
        }
    }

    .emergency-close-card {
        background: #fff5f5;
        border: 2px solid #fee2e2;
        border-radius: 12px;
        padding: 24px;
        margin-bottom: 32px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .emergency-close-info h3 {
        margin: 0;
        font-size: 16px;
        color: #991b1b;
        font-weight: 700;
    }

    .emergency-close-info p {
        margin: 4px 0 0 0;
        font-size: 13px;
        color: #b91c1c;
        opacity: 0.8;
    }

    .switch {
        position: relative;
        display: inline-block;
        width: 52px;
        height: 28px;
    }

    .switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }

    .slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #cbd5e1;
        transition: .4s;
        border-radius: 34px;
    }

    .slider:before {
        position: absolute;
        content: "";
        height: 20px;
        width: 20px;
        left: 4px;
        bottom: 4px;
        background-color: white;
        transition: .4s;
        border-radius: 50%;
    }

    input:checked+.slider {
        background-color: #ef4444;
    }

    input:checked+.slider:before {
        transform: translateX(24px);
    }

    .actions {
        margin-top: 32px;
        padding-top: 32px;
        border-top: 1px solid var(--color-border);
        display: flex;
        justify-content: flex-end;
        gap: 12px;
    }
</style>

<div class="settings-container">
    <div class="settings-header">
        <p class="page-subtitle">Configure your restaurant's availability and timezone.</p>
    </div>

    <!-- Live Status Banner -->
    <div class="live-status-banner">
        <div class="live-time-group">
            <div class="live-time-label">Current Nepal Time</div>
            <div class="live-time-value" id="currentNepalTime">--:--:--</div>
        </div>
        <div id="currentStoreStatus" class="live-status-badge">Checking...</div>
    </div>

    <div class="settings-card">
        <form id="timeForm">
            <!-- Emergency Close Option -->
            <div class="emergency-close-card">
                <div class="emergency-close-info">
                    <h3>Emergency / Event Mode</h3>
                    <p>When enabled, the restaurant will appear <strong>COMPLETELY CLOSED</strong> regardless of
                        operating hours.</p>
                </div>
                <label class="switch">
                    <input type="checkbox" name="is_closed" <?php echo $settings['is_closed'] ? 'checked' : ''; ?> disabled>
                    <span class="slider round"></span>
                </label>
            </div>

            <!-- Timezone -->
            <div class="form-group">
                <label class="form-label">Regional Timezone</label>
                <div class="form-description">Fixed to local business region. Cannot be changed.</div>
                <div class="custom-input-wrapper">
                    <input type="text" class="custom-input" value="Asia/Kathmandu (Nepal Time)" readonly
                        style="background-color: #f1f5f9; color: #64748b; cursor: not-allowed;">
                    <input type="hidden" name="timezone" value="Asia/Kathmandu">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Daily Schedule</label>
                <div class="form-description">Select your standard opening and closing times in 12-hour format.</div>

                <div class="time-grid">
                    <?php
                    // Helper to parse 24h to 12h parts
                    // Helper to parse 24h to 12h parts
                    function get12hParts($time24)
                    {
                        if (empty($time24)) {
                            return ['h' => '', 'm' => '', 'p' => ''];
                        }
                        $dt = strtotime($time24);
                        if ($dt === false) return ['h' => '', 'm' => '', 'p' => ''];
                        return [
                            'h' => date("h", $dt),
                            'm' => date("i", $dt),
                            'p' => date("A", $dt)
                        ];
                    }
                    $openParts = get12hParts($settings['opening_time']);
                    $closeParts = get12hParts($settings['closing_time']);
                    ?>
                    <div>
                        <label class="form-label" style="font-size: 13px; color: var(--color-text-muted);">Opens
                            At</label>
                        <div class="time-picker-custom">
                            <select name="open_h" class="custom-select" disabled>
                                <option value="" <?php echo $openParts['h'] === '' ? 'selected' : ''; ?>>--</option>
                                <?php for ($i = 1; $i <= 12; $i++):
                                    $v = str_pad($i, 2, '0', STR_PAD_LEFT); ?>
                                    <option value="<?php echo $v; ?>" <?php echo $openParts['h'] == $v ? 'selected' : ''; ?>>
                                        <?php echo $v; ?></option>
                                <?php endfor; ?>
                            </select>
                            <span style="font-weight: bold;">:</span>
                            <select name="open_m" class="custom-select" disabled>
                                <option value="" <?php echo $openParts['m'] === '' ? 'selected' : ''; ?>>--</option>
                                <?php for ($i = 0; $i < 60; $i += 1):
                                    $v = str_pad($i, 2, '0', STR_PAD_LEFT); ?>
                                    <option value="<?php echo $v; ?>" <?php echo $openParts['m'] == $v ? 'selected' : ''; ?>>
                                        <?php echo $v; ?></option>
                                <?php endfor; ?>
                            </select>
                            <select name="open_p" class="custom-select time-picker-period" disabled>
                                <option value="" <?php echo $openParts['p'] === '' ? 'selected' : ''; ?>>--</option>
                                <option value="AM" <?php echo $openParts['p'] == 'AM' ? 'selected' : ''; ?>>AM</option>
                                <option value="PM" <?php echo $openParts['p'] == 'PM' ? 'selected' : ''; ?>>PM</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="form-label" style="font-size: 13px; color: var(--color-text-muted);">Closes
                            At</label>
                        <div class="time-picker-custom">
                            <select name="close_h" class="custom-select" disabled>
                                <option value="" <?php echo $closeParts['h'] === '' ? 'selected' : ''; ?>>--</option>
                                <?php for ($i = 1; $i <= 12; $i++):
                                    $v = str_pad($i, 2, '0', STR_PAD_LEFT); ?>
                                    <option value="<?php echo $v; ?>" <?php echo $closeParts['h'] == $v ? 'selected' : ''; ?>>
                                        <?php echo $v; ?></option>
                                <?php endfor; ?>
                            </select>
                            <span style="font-weight: bold;">:</span>
                            <select name="close_m" class="custom-select" disabled>
                                <option value="" <?php echo $closeParts['m'] === '' ? 'selected' : ''; ?>>--</option>
                                <?php for ($i = 0; $i < 60; $i += 1):
                                    $v = str_pad($i, 2, '0', STR_PAD_LEFT); ?>
                                    <option value="<?php echo $v; ?>" <?php echo $closeParts['m'] == $v ? 'selected' : ''; ?>>
                                        <?php echo $v; ?></option>
                                <?php endfor; ?>
                            </select>
                            <select name="close_p" class="custom-select time-picker-period" disabled>
                                <option value="" <?php echo $closeParts['p'] === '' ? 'selected' : ''; ?>>--</option>
                                <option value="AM" <?php echo $closeParts['p'] == 'AM' ? 'selected' : ''; ?>>AM</option>
                                <option value="PM" <?php echo $closeParts['p'] == 'PM' ? 'selected' : ''; ?>>PM</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="info-box">
                <div class="info-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                </div>
                <div class="info-content">
                    <h4>Smart Overnight Handling</h4>
                    <p>If you set a closing time (e.g., 02:00 AM) that is earlier than your opening time, the system
                        automatically understands this as closing the following day.</p>
                </div>
            </div>

            <div class="actions">
                <button type="button" id="editBtn" class="btn-edit">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    Edit Settings
                </button>
                <button type="button" id="saveBtn" class="btn-save" style="display: none;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
    (function () {
        const timezone = 'Asia/Kathmandu';
        const config = {
            openingTime: '<?php echo $settings['opening_time']; ?>',
            closingTime: '<?php echo $settings['closing_time']; ?>',
            isClosedManual: <?php echo $settings['is_closed'] ? 'true' : 'false'; ?>
        };

        const editBtn = document.getElementById('editBtn');
        const saveBtn = document.getElementById('saveBtn');
        const inputs = document.querySelectorAll('select.custom-select, input[name="is_closed"]');

        // Toggle Edit Mode
        if (editBtn) {
            editBtn.addEventListener('click', function() {
                inputs.forEach(input => input.disabled = false);
                editBtn.style.display = 'none';
                saveBtn.style.display = 'block';
            });
        }

        function getNepalTime() {
            try {
                const now = new Date();
                const formatter = new Intl.DateTimeFormat('en-US', {
                    timeZone: timezone,
                    year: 'numeric', month: '2-digit', day: '2-digit',
                    hour: '2-digit', minute: '2-digit', second: '2-digit',
                    hourCycle: 'h23'
                });

                const parts = formatter.formatToParts(now);
                const map = {};
                parts.forEach(p => map[p.type] = p.value);

                return new Date(
                    parseInt(map.year), parseInt(map.month) - 1, parseInt(map.day),
                    parseInt(map.hour), parseInt(map.minute), parseInt(map.second)
                );
            } catch (e) {
                console.error("Nepal Time Calculation Error:", e);
                return new Date(); // Fallback to system time
            }
        }

        function updateConfigFromUI() {
            const openH = document.querySelector('select[name="open_h"]').value;
            const openM = document.querySelector('select[name="open_m"]').value;
            const openP = document.querySelector('select[name="open_p"]').value;

            const closeH = document.querySelector('select[name="close_h"]').value;
            const closeM = document.querySelector('select[name="close_m"]').value;
            const closeP = document.querySelector('select[name="close_p"]').value;

            const to24 = (h, m, p) => {
                let hour = parseInt(h);
                if (p === 'PM' && hour < 12) hour += 12;
                if (p === 'AM' && hour === 12) hour = 0;
                return { h: hour, m: parseInt(m) };
            };

            const open = to24(openH, openM, openP);
            const close = to24(closeH, closeM, closeP);

            config.openingTime = `${String(open.h).padStart(2, '0')}:${String(open.m).padStart(2, '0')}`;
            config.closingTime = `${String(close.h).padStart(2, '0')}:${String(close.m).padStart(2, '0')}`;
            config.isClosedManual = document.querySelector('input[name="is_closed"]').checked;

            config.displayOpen = `${openH}:${openM} ${openP}`;
            config.displayClose = `${closeH}:${closeM} ${closeP}`;
            config.isOvernight = (close.h * 60 + close.m) < (open.h * 60 + open.m);
        }

        function updateLiveStatus() {
            updateConfigFromUI();
            const now = getNepalTime();

            const displayTime = now.toLocaleTimeString('en-US', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' });
            document.getElementById('currentNepalTime').innerHTML = `${displayTime} <span class="live-status-details" id="liveStatusDetails"></span>`;

            let isOpen = false;
            if (!config.isClosedManual) {
                const [openH, openM] = config.openingTime.split(':').map(Number);
                const [closeH, closeM] = config.closingTime.split(':').map(Number);
                const nowMins = now.getHours() * 60 + now.getMinutes();
                const openMins = openH * 60 + openM;
                const closeMins = closeH * 60 + closeM;

                if (closeMins > openMins) {
                    isOpen = nowMins >= openMins && nowMins < closeMins;
                } else {
                    isOpen = nowMins >= openMins || nowMins < closeMins;
                }
            }

            const badge = document.getElementById('currentStoreStatus');
            const detailsEl = document.getElementById('liveStatusDetails');

            if (config.isClosedManual) {
                badge.textContent = 'Manually Closed';
                badge.className = 'live-status-badge status-closed';
                detailsEl.textContent = '(Override Active)';
            } else if (isOpen) {
                badge.textContent = 'Open Now';
                badge.className = 'live-status-badge status-open';
                detailsEl.textContent = `(Until ${config.displayClose}${config.isOvernight ? ' tomorrow' : ''})`;
            } else {
                badge.textContent = 'Closed Now';
                badge.className = 'live-status-badge status-closed';
                detailsEl.textContent = `(Opens ${config.displayOpen})`;
            }
        }

        if (saveBtn) {
            saveBtn.addEventListener('click', async function () {
                const originalHTML = this.innerHTML;
                this.disabled = true;
                this.innerHTML = '<span class="loading-spinner"></span> Saving...';

                updateConfigFromUI();

                try {
                    const response = await fetch('api/save_time.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            opening_time: config.openingTime,
                            closing_time: config.closingTime,
                            is_closed: config.isClosedManual
                        })
                    });

                    const result = await response.json();
                    if (result.success) {
                        this.style.background = '#10b981';
                        this.innerHTML = '✓ Saved Successfully';
                        setTimeout(() => {
                            this.disabled = false;
                            this.style.background = '';
                            this.innerHTML = originalHTML;

                            // Exit edit mode
                            inputs.forEach(input => input.disabled = true);
                            saveBtn.style.display = 'none';
                            editBtn.style.display = 'flex';
                        }, 1500);
                    } else {
                        throw new Error(result.error || 'Failed to save');
                    }
                } catch (error) {
                    console.error('Save Error:', error);
                    this.style.background = '#ef4444';
                    this.innerHTML = '✕ Error Saving';
                    setTimeout(() => {
                        this.disabled = false;
                        this.style.background = '';
                        this.innerHTML = originalHTML;
                    }, 3000);
                }
            });
        }

        document.querySelectorAll('select, input[type="checkbox"]').forEach(el => {
            el.addEventListener('change', updateLiveStatus);
        });

        setInterval(updateLiveStatus, 1000);
        updateLiveStatus();
    })();
</script>
