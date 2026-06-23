<?php
/**
 * Delivery Settings - Manage Delivery Charges and Range
 */

require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();
requireAdminPin();

// Branch context.
global $pdo;
$selectedBranchId = getAdminBranchId();
$branchName = null;
if ($selectedBranchId) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM branches WHERE id = ? LIMIT 1");
        $stmt->execute([$selectedBranchId]);
        $branchName = $stmt->fetchColumn() ?: null;
    } catch (Exception $e) { }
}
// ---

$pageTitle = 'Delivery Settings';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/../config/load_security.php';

$settings = getDeliverySettings($selectedBranchId);
?>

<link rel="preconnect" href="https://fonts.bunny.net">
<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
<link href="https://fonts.bunny.net/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Poppins:wght@400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

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
        --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    }

    body {
        font-family: var(--font-family) !important;
        background-color: var(--color-bg);
        color: var(--color-text-main);
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
        min-height: 40px;
    }

    .input-wrapper {
        position: relative;
        display: flex;
        align-items: center;
    }

    .input-prefix {
        position: absolute;
        left: 16px;
        color: var(--color-text-muted);
        font-weight: 600;
        font-size: 15px;
    }

    .input-suffix {
        position: absolute;
        right: 16px;
        color: var(--color-text-muted);
        font-weight: 600;
        font-size: 14px;
    }

    .custom-input {
        width: 100%;
        padding: 12px 45px; /* Balanced padding for prefix and suffix spacing */
        font-family: var(--font-family);
        font-size: 16px;
        font-weight: 700;
        color: var(--color-text-main);
        background-color: #fff;
        border: 1px solid var(--color-border);
        border-radius: 12px;
        transition: all 0.2s ease;
        text-align: center;
    }

    .custom-input:focus {
        outline: none;
        border-color: var(--color-primary);
        box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
    }

    .input-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 24px;
    }

    @media (max-width: 600px) {
        .input-grid {
            grid-template-columns: 1fr;
        }
    }

    .info-box {
        background: #f0f9ff;
        border: 1px solid #bae6fd;
        border-radius: 12px;
        padding: 16px;
        margin-top: 32px;
        display: flex;
        gap: 16px;
    }

    .info-icon {
        color: #0369a1;
        font-size: 20px;
        flex-shrink: 0;
    }

    .info-content h4 {
        margin: 0 0 4px 0;
        font-size: 14px;
        font-weight: 600;
        color: #0c4a6e;
    }

    .info-content p {
        margin: 0;
        font-size: 13px;
        color: #0369a1;
        line-height: 1.5;
    }

    .btn-edit {
        background-color: #f1f5f9;
        color: var(--color-text-main);
        border: 1px solid var(--color-border);
        padding: 14px 40px;
        border-radius: 12px;
        font-family: var(--font-family);
        font-size: 15px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .btn-edit:hover {
        background-color: #e2e8f0;
    }

    .btn-save {
        background-color: var(--color-text-main);
        color: white;
        border: none;
        padding: 14px 40px;
        border-radius: 12px;
        font-family: var(--font-family);
        font-size: 15px;
        font-weight: 700;
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

    .custom-input:disabled {
        background-color: #f8fafc;
        cursor: not-allowed;
        border-color: #e2e8f0;
        color: #94a3b8;
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
        to { transform: rotate(360deg); }
    }

    .stat-pills {
        display: flex;
        gap: 12px;
        margin-bottom: 32px;
    }

    .stat-pill {
        flex: 1;
        background: #fff;
        border: 1px solid var(--color-border);
        padding: 16px;
        border-radius: 16px;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .stat-pill-label {
        font-size: 11px;
        font-weight: 700;
        color: var(--color-text-muted);
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .stat-pill-value {
        font-size: 20px;
        font-weight: 800;
        color: var(--color-text-main);
        font-family: 'Poppins', sans-serif;
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

        <p class="page-subtitle">Configure delivery charges, rates per kilometer, and service radius.</p>
    </div>

    <div class="stat-pills">
        <div class="stat-pill">
            <span class="stat-pill-label">Base Fee</span>
            <span class="stat-pill-value" id="pillBaseFee">Rs. <?php echo $settings['base_fee']; ?></span>
        </div>
        <div class="stat-pill">
            <span class="stat-pill-label">Rate / KM</span>
            <span class="stat-pill-value" id="pillRateKm">Rs. <?php echo $settings['rate_per_km']; ?></span>
        </div>
        <div class="stat-pill">
            <span class="stat-pill-label">Max Radius</span>
            <span class="stat-pill-value" id="pillMaxDist"><?php echo $settings['max_distance']; ?> KM</span>
        </div>
    </div>

    <div class="settings-card">
        <form id="deliveryForm">
            <div class="input-grid">
                <div class="form-group">
                    <label class="form-label">Base Delivery Charge</label>
                    <div class="form-description">The starting fee for any delivery.</div>
                    <div class="input-wrapper">
                        <span class="input-prefix">Rs.</span>
                        <input type="number" name="base_fee" class="custom-input" value="<?php echo $settings['base_fee']; ?>" step="1" min="0" disabled>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Distance Rate (Per KM)</label>
                    <div class="form-description">Fee added per KM (Calculated precisely to the meter).</div>
                    <div class="input-wrapper">
                        <span class="input-prefix">Rs.</span>
                        <input type="number" name="rate_per_km" class="custom-input" value="<?php echo $settings['rate_per_km']; ?>" step="1" min="0" disabled>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Maximum Delivery Distance</label>
                    <div class="form-description">Orders beyond this distance will be automatically rejected.</div>
                    <div class="input-wrapper">
                        <input type="number" name="max_distance" class="custom-input" value="<?php echo $settings['max_distance']; ?>" step="0.5" min="1" disabled>
                        <span class="input-suffix">KM</span>
                    </div>
                </div>
            </div>

            <div class="info-box">
                <div class="info-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                        <circle cx="12" cy="10" r="3"></circle>
                    </svg>
                </div>
                <div class="info-content">
                    <h4>Dynamic Calculation</h4>
                    <p>The system uses <b>Mapbox Road Distance</b> (driving distance) to calculate fees. Fees are automatically rounded to the nearest multiple of 5 for a cleaner total.</p>
                </div>
            </div>

            <div class="info-box" style="background: #fdfbf7; border-color: #f3e8d2;">
                <div class="info-icon" style="color: #854d0e;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="16" x2="12" y2="12"></line>
                        <line x1="12" y1="8" x2="12.01" y2="8"></line>
                    </svg>
                </div>
                <div class="info-content">
                    <h4 style="color: #713f12;">Price Smoothing Logic</h4>
                    <p style="color: #854d0e;">For a professional look, the system rounds the final fee to the nearest <b>Rs. 5</b>. 
                        <br>• Rs. 61 or 62 becomes <b>Rs. 60</b>
                        <br>• Rs. 63 or 64 becomes <b>Rs. 65</b>
                    </p>
                </div>
            </div>

            <div class="actions">
                <button type="button" id="editBtn" class="btn-edit">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                    Edit Settings
                </button>
                <button type="button" id="saveDeliveryBtn" class="btn-save" style="display: none;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

<script>
    const editBtn = document.getElementById('editBtn');
    const saveBtn = document.getElementById('saveDeliveryBtn');
    const inputs = document.querySelectorAll('.custom-input');

    // Toggle Edit Mode
    editBtn.addEventListener('click', function() {
        inputs.forEach(input => input.disabled = false);
        editBtn.style.display = 'none';
        saveBtn.style.display = 'block';
        inputs[0].focus();
    });

    saveBtn.addEventListener('click', async function () {
        const btn = this;
        const originalHTML = btn.innerHTML;
        const form = document.getElementById('deliveryForm');
        const formData = new FormData(form);
        
        const data = {
            base_fee: formData.get('base_fee'),
            rate_per_km: formData.get('rate_per_km'),
            max_distance: formData.get('max_distance')
        };

        btn.disabled = true;
        btn.innerHTML = '<span class="loading-spinner"></span> Saving...';

        try {
            const response = await fetch('api/save_delivery.php?branch=<?php echo intval($selectedBranchId ?? 0); ?>', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            const result = await response.json();
            if (result.success) {
                // Update pills
                document.getElementById('pillBaseFee').textContent = `Rs. ${result.base_fee}`;
                document.getElementById('pillRateKm').textContent = `Rs. ${result.rate_per_km}`;
                document.getElementById('pillMaxDist').textContent = `${result.max_distance} KM`;

                btn.style.background = '#10b981';
                btn.innerHTML = '✓ Saved Successfully';
                
                setTimeout(() => {
                    btn.disabled = false;
                    btn.style.background = '';
                    btn.innerHTML = originalHTML;
                    
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
            btn.style.background = '#ef4444';
            btn.innerHTML = '✕ Error Saving';
            setTimeout(() => {
                btn.disabled = false;
                btn.style.background = '';
                btn.innerHTML = originalHTML;
            }, 3000);
        }
    });

    // Real-time pill updates
    document.querySelectorAll('input').forEach(input => {
        input.addEventListener('input', function() {
            const val = this.value || 0;
            if (this.name === 'base_fee') document.getElementById('pillBaseFee').textContent = `Rs. ${val}`;
            if (this.name === 'rate_per_km') document.getElementById('pillRateKm').textContent = `Rs. ${val}`;
            if (this.name === 'max_distance') document.getElementById('pillMaxDist').textContent = `${val} KM`;
        });
    });
</script>
