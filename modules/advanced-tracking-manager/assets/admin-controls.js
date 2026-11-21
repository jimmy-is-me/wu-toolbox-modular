(() => {
    'use strict';
    const root = document.querySelector('.wutm-atm-wrap');
    if (!root) return;
    const dirty = new Set();

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
    window.addEventListener('beforeunload', event => {
        if (!dirty.size) return;
        event.preventDefault();
        event.returnValue = '';
    });
    root.querySelector('[data-atm-copy]')?.addEventListener('click', async () => {
        const text = root.querySelector('#wutm-atm-audit-summary');
        const status = root.querySelector('[data-atm-copy-status]');
        try {
            await navigator.clipboard.writeText(text.value);
            status.textContent = '已複製';
        } catch (_) {
            text.select();
            status.textContent = '已選取摘要，請使用複製快捷鍵。';
        }
    });
})();
