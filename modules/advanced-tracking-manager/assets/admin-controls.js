(() => {
    'use strict';
    const root = document.querySelector('.wutm-atm-wrap');
    if (!root) return;
    const dirty = new Set();

    const purchase = root.querySelector('input[name="events[]"][value="Purchase"]');
    const purchaseSettings = root.querySelector('[data-atm-purchase-settings]');
    purchase?.addEventListener('change', () => {
        purchaseSettings.hidden = !purchase.checked;
    });

    root.addEventListener('input', event => {
        if (event.target.form?.matches('[data-atm-settings]')) dirty.add(event.target.form);
    });
    root.addEventListener('submit', event => {
        const confirmation = event.target.dataset.atmConfirm;
        if (confirmation && !window.confirm(confirmation)) {
            event.preventDefault();
            return;
        }
        if (!event.defaultPrevented) dirty.delete(event.target);
    });
    root.querySelector('.atm-tabs')?.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link || !dirty.size || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        if (!window.confirm('設定尚未儲存。確定要切換分頁？')) event.preventDefault();
        else dirty.clear();
    });
    window.addEventListener('beforeunload', event => {
        if (!dirty.size) return;
        event.preventDefault();
        event.returnValue = '';
    });
})();
