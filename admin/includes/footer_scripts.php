<?php
// Enhanced Payment UI Logic
require_once __DIR__ . '/auth.php';
requireAdminLogin();
?>
<style>
    /* Modern Payment Confirmation Modal */
    .payment-confirm-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: 999999;
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }

    .payment-confirm-modal.active {
        opacity: 1;
        visibility: visible;
    }

    .payment-confirm-backdrop {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(8px);
    }

    .payment-confirm-content {
        position: relative;
        background: #ffffff;
        width: 90%;
        max-width: 340px;
        padding: 24px;
        border-radius: 16px;
        box-shadow: 0 20px 40px -10px rgba(0, 0, 0, 0.2);
        transform: translateY(20px) scale(0.95);
        transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .payment-confirm-modal.active .payment-confirm-content {
        transform: translateY(0) scale(1);
    }

    .pc-icon-wrapper {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: #eff6ff;
        color: #3b82f6;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 16px;
    }

    .pc-icon-wrapper svg {
        width: 24px;
        height: 24px;
    }

    .payment-confirm-content h3 {
        margin: 0 0 8px;
        font-size: 18px;
        font-weight: 700;
        color: #0f172a;
        text-align: center;
    }

    .payment-confirm-content p {
        margin: 0 0 24px;
        font-size: 14px;
        color: #475569;
        line-height: 1.5;
        text-align: center;
    }

    .payment-confirm-actions {
        display: flex;
        gap: 12px;
    }

    .pc-btn-cancel {
        flex: 1;
        padding: 10px 16px;
        background: #f8fafc;
        color: #475569;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
    }

    .pc-btn-cancel:hover {
        background: #f1f5f9;
        color: #1e293b;
    }

    .pc-btn-confirm {
        flex: 1;
        padding: 10px 16px;
        background: #60bb46;
        color: #ffffff;
        border: none;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 4px 6px -1px rgba(96, 187, 70, 0.2);
    }

    .pc-btn-confirm:hover {
        background: #4ca336;
        box-shadow: 0 10px 15px -3px rgba(96, 187, 70, 0.3);
        transform: translateY(-2px);
    }
</style>

<!-- Modern Modal HTML injected to body bottom via standard inclusion -->
<div id="paymentConfirmModal" class="payment-confirm-modal">
    <div class="payment-confirm-backdrop"></div>
    <div class="payment-confirm-content">
        <div class="pc-icon-wrapper">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
        </div>
        <h3 id="paymentConfirmTitle">Confirm Update</h3>
        <p id="paymentConfirmMessage">Are you sure you want to update?</p>
        <div class="payment-confirm-actions">
            <button id="paymentCancelBtn" class="pc-btn-cancel" onclick="closePaymentModal()">Cancel</button>
            <button id="paymentConfirmBtn" class="pc-btn-confirm">Confirm & Update</button>
        </div>
    </div>
</div>

<script>
    let pendingPaymentUpdate = null;

    function openPaymentModal(title, message, isAlert = false, onConfirm = null) {
        document.getElementById('paymentConfirmTitle').innerText = title;
        document.getElementById('paymentConfirmMessage').innerHTML = message;

        const confirmBtn = document.getElementById('paymentConfirmBtn');
        const cancelBtn = document.getElementById('paymentCancelBtn');
        const iconWrapper = document.querySelector('.pc-icon-wrapper');

        if (isAlert) {
            // Error / Alert Mode
            iconWrapper.style.color = '#ef4444';
            iconWrapper.style.background = '#fee2e2';
            iconWrapper.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>`;

            cancelBtn.style.display = 'none';
            confirmBtn.innerText = 'Wait, go back';
            confirmBtn.style.background = '#0f172a';
            confirmBtn.style.boxShadow = 'none';
            confirmBtn.onclick = closePaymentModal;
        } else {
            // Standard Confirmation Mode
            iconWrapper.style.color = '#60bb46';
            iconWrapper.style.background = '#e8f4e5';
            iconWrapper.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>`;

            cancelBtn.style.display = 'block';
            confirmBtn.innerText = 'Confirm & Update';
            confirmBtn.style.background = '#60bb46';
            confirmBtn.style.boxShadow = '0 4px 6px -1px rgba(96, 187, 70, 0.2)';
            confirmBtn.onclick = function () {
                if (onConfirm) onConfirm();
                closePaymentModal();
            };
        }

        document.getElementById('paymentConfirmModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closePaymentModal() {
        document.getElementById('paymentConfirmModal').classList.remove('active');
        document.body.style.overflow = '';
    }

    function customConfirmPaymentUpdate(orderId, mode) {
        let msg = '';
        let title = '';
        if (mode === 'cash') {
            title = 'Confirm Cash Payment';
            msg = 'Customer paid FULL amount via <b style="color:#047857;">CASH</b>?';
        } else if (mode === 'cod_online') {
            title = 'Confirm Online Payment';
            msg = 'Customer paid FULL amount via <b style="color:#2563eb;">ONLINE TRANSFER</b>?';
        }

        openPaymentModal(title, msg, false, function () {
            submitPaymentUpdate(orderId, mode, 0, 0);
        });
    }

    function customSubmitSplitPay(orderId, total) {
        const cashInput = document.getElementById(`split-cash-${orderId}`);
        const onlineInput = document.getElementById(`split-online-${orderId}`);

        const cashVal = parseFloat(cashInput.value || 0);
        const onlineVal = parseFloat(onlineInput.value || 0);
        const sum = cashVal + onlineVal;

        if (Math.abs(sum - total) > 0.5) {
            const diff = Math.abs(sum - total).toFixed(2);
            let errMsg = `Expected Total: <b style="color:#0f172a;">Rs. ${total.toFixed(2)}</b><br>`;
            errMsg += `Entered Total: <b style="color:#0f172a;">Rs. ${sum.toFixed(2)}</b><br><br>`;
            errMsg += `Difference: <b style="color:#ef4444;">Rs. ${diff}</b>`;
            openPaymentModal('Amount Mismatch', errMsg, true);
            return;
        }

        const msg = `<div style="background:#f8fafc; padding:12px; border-radius:8px; border:1px solid #e2e8f0;">
            <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
                <span style="color:#64748b; font-size:13px;">Cash:</span> <b style="color:#047857;">Rs. ${cashVal.toFixed(2)}</b>
            </div>
            <div style="display:flex; justify-content:space-between;">
                <span style="color:#64748b; font-size:13px;">Online:</span> <b style="color:#2563eb;">Rs. ${onlineVal.toFixed(2)}</b>
            </div>
        </div>`;

        openPaymentModal('Confirm Split Payment', msg, false, function () {
            submitPaymentUpdate(orderId, 'split', cashVal, onlineVal);
        });
    }

    function submitPaymentUpdate(orderId, mode, cashAmt, onlineAmt) {
        const btn = document.querySelector('.btn-update-payment');
        if (btn) btn.innerHTML = 'Updating...';

        // Prepare form data
        const formData = new FormData();
        formData.append('order_id', orderId);
        formData.append('mode', mode);
        if (mode === 'split') {
            formData.append('cash_amount', cashAmt);
            formData.append('online_amount', onlineAmt);
        }

        fetch('api/update_payment.php', {
            method: 'POST',
            body: formData
        })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    openPaymentModal('Update Failed', (data.error || 'Unknown error occurred.'), true);
                    if (btn) btn.innerHTML = 'Update Payment';
                }
            })
            .catch(err => {
                console.error(err);
                openPaymentModal('Connection Error', 'Failed to reach the server. Please check your internet connection and try again.', true);
                if (btn) btn.innerHTML = 'Update Payment';
            });
    }

    function calculateSplit(orderId, total, changed) {
        const cashInput = document.getElementById('split-cash-' + orderId);
        const onlineInput = document.getElementById('split-online-' + orderId);
        const checking = document.getElementById('split-check-' + orderId);

        let cashVal = parseFloat(cashInput.value) || 0;
        let onlineVal = parseFloat(onlineInput.value) || 0;

        // Automatically fill the other input field with the remaining balance
        if (changed === 'cash') {
            if (cashVal <= total && cashInput.value !== '') {
                onlineInput.value = (total - cashVal).toFixed(2);
            } else if (cashInput.value === '') {
                onlineInput.value = '';
            } else {
                onlineInput.value = '0.00';
            }
        } else if (changed === 'online') {
            if (onlineVal <= total && onlineInput.value !== '') {
                cashInput.value = (total - onlineVal).toFixed(2);
            } else if (onlineInput.value === '') {
                cashInput.value = '';
            } else {
                cashInput.value = '0.00';
            }
        }

        // Recalculate sum state for the status indicator
        let cash = parseFloat(cashInput.value) || 0;
        let online = parseFloat(onlineInput.value) || 0;
        let sum = cash + online;
        let diff = total - sum;

        if (Math.abs(diff) < 0.01) {
            checking.style.background = '#dcfce7';
            checking.style.color = '#16a34a';
            checking.style.borderColor = '#bbf7d0';
            checking.innerText = 'Total Matches Perfectly!';
        } else if (diff > 0) {
            checking.style.background = '#fef3c7';
            checking.style.color = '#d97706';
            checking.style.borderColor = '#fde68a';
            checking.innerText = 'Remaining: Rs. ' + diff.toFixed(2);
        } else {
            checking.style.background = '#fee2e2';
            checking.style.color = '#ef4444';
            checking.style.borderColor = '#fecaca';
            checking.innerText = 'Exceeded by: Rs. ' + Math.abs(diff).toFixed(2);
        }
    }
</script>
