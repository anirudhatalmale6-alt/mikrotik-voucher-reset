/**
 * VoucherReset - Frontend JavaScript
 */
(function () {
    'use strict';

    // State
    let selectedRouterId = null;
    let selectedRouterName = '';
    let isSubmitting = false;

    // DOM elements
    const routerGrid = document.getElementById('router-grid');
    const voucherForm = document.getElementById('voucher-form-wrapper');
    const resultWrapper = document.getElementById('result-wrapper');
    const selectedRouterLabel = document.getElementById('selected-router-name');
    const voucherInput = document.getElementById('voucher-input');
    const resetBtn = document.getElementById('reset-btn');
    const resetForm = document.getElementById('reset-form');
    const changeRouterBtn = document.getElementById('change-router-btn');
    const tryAgainBtn = document.getElementById('try-again-btn');

    // ---- Initialize ----
    function init() {
        if (!routerGrid) return;

        checkAllStatuses();
        bindEvents();
    }

    // ---- Event Bindings ----
    function bindEvents() {
        // Router card clicks
        routerGrid.addEventListener('click', function (e) {
            const card = e.target.closest('.router-card');
            if (card) {
                selectRouter(card);
            }
        });

        // Form submission
        if (resetForm) {
            resetForm.addEventListener('submit', function (e) {
                e.preventDefault();
                handleReset();
            });
        }

        // Change router
        if (changeRouterBtn) {
            changeRouterBtn.addEventListener('click', function () {
                deselectRouter();
            });
        }

        // Try again
        if (tryAgainBtn) {
            tryAgainBtn.addEventListener('click', function () {
                showForm();
            });
        }
    }

    // ---- Status Checks ----
    function checkAllStatuses() {
        const cards = routerGrid.querySelectorAll('.router-card');
        cards.forEach(function (card) {
            const id = card.dataset.id;
            checkStatus(id, card);
        });
    }

    function checkStatus(id, card) {
        const dot = card.querySelector('.status-dot');
        const label = card.querySelector('.router-status-label');

        dot.className = 'status-dot checking';
        label.textContent = 'Checking...';

        fetch('api/status.php?id=' + encodeURIComponent(id))
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.status === 'online') {
                    dot.className = 'status-dot online';
                    label.textContent = 'Online';
                } else {
                    dot.className = 'status-dot offline';
                    label.textContent = 'Offline';
                }
            })
            .catch(function () {
                dot.className = 'status-dot offline';
                label.textContent = 'Offline';
            });
    }

    // ---- Router Selection ----
    function selectRouter(card) {
        // Remove active from all
        routerGrid.querySelectorAll('.router-card').forEach(function (c) {
            c.classList.remove('active');
        });

        card.classList.add('active');
        selectedRouterId = card.dataset.id;
        selectedRouterName = card.dataset.name;

        showForm();
    }

    function deselectRouter() {
        selectedRouterId = null;
        selectedRouterName = '';
        routerGrid.querySelectorAll('.router-card').forEach(function (c) {
            c.classList.remove('active');
        });
        hideForm();
        hideResult();
    }

    function showForm() {
        if (selectedRouterLabel) {
            selectedRouterLabel.textContent = selectedRouterName;
        }
        if (voucherInput) {
            voucherInput.value = '';
        }
        if (voucherForm) {
            voucherForm.classList.add('visible');
        }
        hideResult();

        // Focus the input after animation
        setTimeout(function () {
            if (voucherInput) voucherInput.focus();
        }, 100);
    }

    function hideForm() {
        if (voucherForm) {
            voucherForm.classList.remove('visible');
        }
    }

    function hideResult() {
        if (resultWrapper) {
            resultWrapper.classList.remove('visible');
        }
    }

    // ---- Reset Submission ----
    function handleReset() {
        if (isSubmitting) return;

        var voucher = voucherInput.value.trim();
        if (!voucher) {
            voucherInput.focus();
            return;
        }

        if (!selectedRouterId) {
            return;
        }

        isSubmitting = true;
        resetBtn.disabled = true;
        resetBtn.innerHTML = '<span class="spinner"></span> Processing...';

        var formData = new FormData();
        formData.append('router_id', selectedRouterId);
        formData.append('voucher', voucher);

        fetch('api/reset.php', {
            method: 'POST',
            body: formData,
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            showResult(data);
        })
        .catch(function () {
            showResult({
                success: false,
                message: 'Network error. Please check your connection and try again.',
                type: 'error',
            });
        })
        .finally(function () {
            isSubmitting = false;
            resetBtn.disabled = false;
            resetBtn.innerHTML = 'Reset Voucher';
        });
    }

    // ---- Results Display ----
    function showResult(data) {
        hideForm();

        var resultCard = document.getElementById('result-card');
        var resultIcon = document.getElementById('result-icon');
        var resultTitle = document.getElementById('result-title');
        var resultMessage = document.getElementById('result-message');

        // Set type class
        resultCard.className = 'result-card result-' + (data.type || 'error');

        // Set icon and title based on type
        var icons = {
            success: '✅',
            expired: '❌',
            not_found: '⚠️',
            error: '❌',
        };

        var titles = {
            success: 'Reset Successful!',
            expired: 'Voucher Expired',
            not_found: 'Voucher Not Found',
            error: 'Connection Failed',
        };

        resultIcon.textContent = icons[data.type] || icons.error;
        resultTitle.textContent = titles[data.type] || titles.error;
        resultMessage.textContent = data.message || 'An unknown error occurred.';

        if (resultWrapper) {
            resultWrapper.classList.add('visible');
        }

        // Scroll to result
        resultWrapper.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    // ---- Boot ----
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
