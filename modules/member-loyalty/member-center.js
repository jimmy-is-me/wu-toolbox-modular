(function () {
    'use strict';
    function updateExpiry() {
        document.querySelectorAll('[data-member-expires]').forEach(node => {
            const seconds = Number(node.dataset.memberExpires) - Date.now() / 1000;
            node.textContent = seconds <= 0 ? '效期已到，等待系統確認會員資格' : '會員效期剩 ' + Math.floor(seconds / 86400) + ' 天 ' + Math.floor((seconds % 86400) / 3600) + ' 小時';
            node.classList.toggle('is-soon', seconds < 7 * 86400);
        });
    }
    updateExpiry();
    if (document.querySelector('[data-member-expires]')) setInterval(updateExpiry, 60000);
    document.addEventListener('click', async event => {
        const copy = event.target.closest('[data-coupon-copy]');
        if (copy) {
            try { await navigator.clipboard.writeText(copy.dataset.couponCopy); copy.textContent = '已複製'; }
            catch (_) { copy.textContent = '請選取上方券碼複製'; }
        }
        if (event.target.closest('[data-card-refresh]')) location.reload();
    });
})();
