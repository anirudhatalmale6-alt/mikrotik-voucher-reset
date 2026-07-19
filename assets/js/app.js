/**
 * VoucherReset - Frontend JavaScript
 */
(function () {
    'use strict';

    var isSubmitting = false;

    var routerSelect = document.getElementById('router-select');
    var statusIndicator = document.getElementById('router-status-indicator');
    var voucherInput = document.getElementById('voucher-input');
    var resetBtn = document.getElementById('reset-btn');
    var resetForm = document.getElementById('reset-form');
    var resultWrapper = document.getElementById('result-wrapper');
    var tryAgainBtn = document.getElementById('try-again-btn');
    var voucherCard = document.querySelector('.voucher-card');

    function init() {
        if (!routerSelect) return;
        bindEvents();
    }

    function bindEvents() {
        routerSelect.addEventListener('change', function () {
            var id = routerSelect.value;
            if (id) {
                checkStatus(id);
                hideResult();
            }
        });

        if (resetForm) {
            resetForm.addEventListener('submit', function (e) {
                e.preventDefault();
                handleReset();
            });
        }

        if (tryAgainBtn) {
            tryAgainBtn.addEventListener('click', function () {
                hideResult();
                if (voucherCard) voucherCard.style.display = '';
                resetForm.style.display = '';
                voucherInput.value = '';
                voucherInput.focus();
            });
        }
    }

    function checkStatus(id) {
        statusIndicator.innerHTML = '<span class="status-dot checking"></span><span class="status-text">Checking...</span>';
        statusIndicator.classList.add('visible');

        fetch('api/status.php?id=' + encodeURIComponent(id))
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.status === 'online') {
                    statusIndicator.innerHTML = '<span class="status-dot online"></span><span class="status-text online">Online</span>';
                } else {
                    statusIndicator.innerHTML = '<span class="status-dot offline"></span><span class="status-text offline">Offline</span>';
                }
            })
            .catch(function () {
                statusIndicator.innerHTML = '<span class="status-dot offline"></span><span class="status-text offline">Offline</span>';
            });
    }

    function handleReset() {
        if (isSubmitting) return;

        var routerId = routerSelect.value;
        var voucher = voucherInput.value.trim();

        if (!routerId) {
            routerSelect.focus();
            return;
        }
        if (!voucher) {
            voucherInput.focus();
            return;
        }

        isSubmitting = true;
        resetBtn.disabled = true;
        resetBtn.innerHTML = '<span class="spinner"></span> Processing...';

        var formData = new FormData();
        formData.append('router_id', routerId);
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
            resetBtn.innerHTML = 'Reset Now';
        });
    }

    function showResult(data) {
        if (voucherCard) voucherCard.style.display = 'none';
        resetForm.style.display = 'none';

        var resultCard = document.getElementById('result-card');
        var resultIcon = document.getElementById('result-icon');
        var resultTitle = document.getElementById('result-title');
        var resultMessage = document.getElementById('result-message');

        resultCard.className = 'result-card result-' + (data.type || 'error');

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
            resultWrapper.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function hideResult() {
        if (resultWrapper) {
            resultWrapper.classList.remove('visible');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
