<?php
/**
 * Branch Selection Component
 * Professional Portable Dark High-Contrast Modal
 */

// Ensure we have access to db and session.
if (!isset($pdo) || !$pdo) {
    require_once __DIR__ . '/../config/db.php';
}
$selectedBranchId = $_SESSION['customer_branch_id'] ?? null;

// Fetch all active branches
$allBranches = [];
try {
    $stmt = $pdo->query("SELECT id, name, address FROM branches WHERE is_active = 1 ORDER BY name ASC");
    if ($stmt) {
        $allBranches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    // Hidden debug info
    echo "<!-- DB Error: " . htmlspecialchars($e->getMessage()) . " -->";
}
?>

<style>
    /* Professional Pure-White Text High-Contrast Modal */
    /* Premium Light Theme / High-Contrast Design */
    :root {
        --jk-orange: #ff4d00;
        --jk-orange-glow: rgba(255, 77, 0, 0.2);
        --jk-bg: #ffffff;
        --jk-card: #f8fafc;
        --jk-border: #e2e8f0;
        --jk-text-main: #0f172a;
        --jk-text-muted: #64748b;
        --jk-white: #ffffff;
    }

    .branch-modal {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: 9999999;
        display: none;
        align-items: center;
        justify-content: center;
        background: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .branch-modal.active {
        display: flex;
        opacity: 1;
    }

    .branch-modal__content {
        background: var(--jk-bg);
        width: 92%;
        max-width: 420px;
        border-radius: 32px;
        box-shadow: 0 50px 100px -20px rgba(15, 23, 42, 0.25);
        position: relative;
        transform: translateY(30px);
        transition: all 0.5s cubic-bezier(0.165, 0.84, 0.44, 1);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        border: 1px solid var(--jk-border);
    }

    @media (min-width: 1024px) {
        .branch-modal__content {
            max-width: 500px;
            border-radius: 40px;
        }
        .branch-modal__header { padding: 60px 50px 30px; }
        .branch-search { padding: 0 50px 20px; }
        .branch-modal__body {
            max-height: 550px;
            gap: 15px;
            padding: 10px 50px 60px;
            overflow-y: auto;
        }
    }

    .branch-modal.active .branch-modal__content {
        transform: translateY(0);
    }

    .branch-modal__strip {
        height: 8px;
        background: var(--jk-orange);
        width: 100%;
    }

    .branch-modal__close {
        position: absolute;
        top: 24px;
        right: 24px;
        background: #f1f5f9;
        border: 1px solid var(--jk-border);
        width: 36px;
        height: 36px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--jk-text-main);
        cursor: pointer;
        transition: all 0.3s ease;
        z-index: 10;
        font-weight: bold;
    }

    .branch-modal__close:hover {
        background: #fee2e2;
        color: #ef4444;
        border-color: #fecaca;
        transform: rotate(90deg);
    }

    .branch-modal__header {
        padding: 40px 30px 20px;
        text-align: center;
    }

    .branch-modal__badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #fff7ed;
        color: var(--jk-orange);
        padding: 6px 14px;
        border-radius: 100px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 16px;
        border: 1px solid #ffedd5;
    }

    .branch-modal__title {
        font-size: 32px;
        font-weight: 800;
        color: var(--jk-text-main);
        margin-bottom: 8px;
        letter-spacing: -1px;
        line-height: 1.1;
    }

    .branch-modal__subtitle {
        font-size: 15px;
        color: var(--jk-text-muted);
        line-height: 1.5;
        font-weight: 500;
    }

    .branch-search {
        padding: 0 30px 20px;
    }

    .branch-search__wrapper {
        position: relative;
        width: 100%;
    }

    .branch-search input {
        width: 100%;
        padding: 14px 20px 14px 48px;
        background: var(--jk-card);
        border: 1.5px solid var(--jk-border);
        border-radius: 16px;
        font-size: 15px;
        font-weight: 600;
        color: var(--jk-text-main);
        outline: none;
        transition: all 0.3s ease;
    }

    .branch-search input:focus {
        background: #fff;
        border-color: var(--jk-orange);
        box-shadow: 0 0 0 4px var(--jk-orange-glow);
    }

    .branch-search__wrapper svg {
        position: absolute;
        left: 16px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--jk-text-muted);
        pointer-events: none;
        opacity: 0.6;
    }

    .branch-modal__body {
        padding: 5px 30px 40px;
        display: flex;
        flex-direction: column;
        gap: 12px;
        max-height: 60vh;
        overflow-y: auto;
        overflow-x: hidden;
    }

    .branch-modal__body::-webkit-scrollbar { width: 6px; }
    .branch-modal__body::-webkit-scrollbar-track { background: transparent; }
    .branch-modal__body::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    .branch-modal__body::-webkit-scrollbar-thumb:hover { background: var(--jk-orange); }

    .branch-card {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 16px 20px;
        background: var(--jk-card);
        border: 1px solid var(--jk-border);
        border-radius: 20px;
        cursor: pointer;
        transition: all 0.3s ease;
        text-align: left;
        width: 100%;
    }

    .branch-card:hover {
        transform: translateY(-2px);
        background: #fff;
        border-color: var(--jk-orange);
        box-shadow: 0 10px 30px -10px rgba(255, 77, 0, 0.15);
    }

    .branch-card__visual {
        width: 46px;
        height: 46px;
        background: #fff;
        border: 1px solid var(--jk-border);
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--jk-text-muted);
        flex-shrink: 0;
        transition: all 0.3s ease;
    }

    .branch-card:hover .branch-card__visual {
        background: var(--jk-orange);
        color: #fff;
        border-color: var(--jk-orange);
    }

    .branch-card__info { flex: 1; }

    .branch-card__name {
        display: block;
        font-size: 17px;
        font-weight: 700;
        color: var(--jk-text-main);
        margin-bottom: 2px;
        letter-spacing: -0.3px;
    }

    .branch-card__meta {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .branch-card__address {
        font-size: 12px;
        color: var(--jk-text-muted);
        font-weight: 500;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 160px;
    }

    .branch-card__tag {
        font-size: 10px;
        font-weight: 800;
        color: #059669;
        background: #dcfce7;
        padding: 2px 8px;
        border-radius: 6px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .branch-card__action {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: #fff;
        border: 1px solid var(--jk-border);
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--jk-text-muted);
        transition: all 0.3s ease;
    }

    .branch-card:hover .branch-card__action {
        background: var(--jk-orange);
        color: #fff;
        transform: translateX(3px);
        border-color: var(--jk-orange);
    }
</style>

<!-- Branch Selection Modal -->
<div class="branch-modal" id="branchModal">
    <div class="branch-modal__content">
        <div class="branch-modal__strip"></div>
        
        <?php if ($selectedBranchId): ?>
            <button class="branch-modal__close" onclick="closeBranchModal()" aria-label="Close modal">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </button>
        <?php endif; ?>

        <div class="branch-modal__header">
            <div class="branch-modal__badge">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
                Find Nearest
            </div>
            <h2 class="branch-modal__title">Where are<br>you?</h2>
            <p class="branch-modal__subtitle">Select a branch for local pricing.</p>
        </div>

        <div class="branch-search">
            <div class="branch-search__wrapper">
                <input type="text" placeholder="Search branch..." id="branchSearchInput" onkeyup="filterBranches()">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </div>
        </div>

        <div class="branch-modal__body" id="branchListBody">
            <?php if (empty($allBranches)): ?>
                <p style="text-align: center; color: var(--jk-white); padding: 20px;">No branches found.</p>
            <?php else: ?>
                <?php foreach ($allBranches as $branch): ?>
                    <button class="branch-card" onclick="selectBranch(<?php echo $branch['id']; ?>)" data-name="<?php echo strtolower(htmlspecialchars($branch['name'])); ?>">
                        <div class="branch-card__visual">
                            <svg width="24" height="24" viewBox="0 0 24 24" stroke="currentColor" fill="none" stroke-width="2.5">
                                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                <polyline points="9 22 9 12 15 12 15 22"></polyline>
                            </svg>
                        </div>
                        <div class="branch-card__info">
                            <span class="branch-card__name"><?php echo htmlspecialchars($branch['name']); ?></span>
                            <div class="branch-card__meta">
                                <span class="branch-card__address"><?php echo htmlspecialchars($branch['address'] ?: 'Nepal'); ?></span>
                                <span class="branch-card__tag">LIVE</span>
                            </div>
                        </div>
                        <div class="branch-card__action">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                        </div>
                    </button>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function filterBranches() {
        const input = document.getElementById('branchSearchInput');
        const filter = input.value.toLowerCase();
        const cards = document.querySelectorAll('.branch-card');
        
        cards.forEach(card => {
            const name = card.getAttribute('data-name');
            if (name.includes(filter)) {
                card.style.display = 'flex';
            } else {
                card.style.display = 'none';
            }
        });
    }

    const isBranchChosen = <?php echo json_encode(!empty($selectedBranchId)); ?>;
    const branchModalEl = document.getElementById('branchModal');

    function openBranchModal() {
        branchModalEl.style.display = 'flex';
        setTimeout(() => {
            branchModalEl.classList.add('active');
            // SCROLL LOCK: Multi-layer protection
            document.body.style.overflow = 'hidden';
            document.documentElement.style.overflow = 'hidden';
            document.body.style.height = '100%';
        }, 10);
    }

    function closeBranchModal() {
        branchModalEl.classList.remove('active');
        // REMOVE SCROLL LOCK
        document.body.style.overflow = '';
        document.documentElement.style.overflow = '';
        document.body.style.height = '';
        setTimeout(() => {
            branchModalEl.style.display = 'none';
        }, 300);
    }

    async function selectBranch(branchId) {
        try {
            const formData = new FormData();
            formData.append('branch_id', branchId);

            const response = await fetch('api/set_customer_branch.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();
            if (data.success) {
                document.body.style.overflow = '';
                document.documentElement.style.overflow = '';
                window.location.reload();
            } else {
                alert(data.message || 'Error selecting branch');
            }
        } catch (err) {
            console.error('Branch selection failed:', err);
        }
    }

    // Auto-open on load if NOT chosen
    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const forceChoose = urlParams.has('change_branch');
        
        if (!isBranchChosen || forceChoose) {
             const path = window.location.pathname;
             const isPrivate = path.includes('track-order') || path.includes('profile');
             
             if (!isPrivate) {
                openBranchModal();
             }
        }
    });

    // Also export for button clicks
    window.openBranchModal = openBranchModal;
    window.closeBranchModal = closeBranchModal;
</script>
