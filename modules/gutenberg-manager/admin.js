(() => {
    const form = document.querySelector('#wutm-block-settings');
    if (!form) return;
    const boxes = [...form.querySelectorAll('[data-block]')];
    const scan = document.querySelector('#wutm-block-scan');
    const status = document.querySelector('#wutm-block-status');
    form.addEventListener('submit', () => {
        form.elements.hidden_blocks.value = JSON.stringify(boxes.filter(box => box.checked).map(box => box.dataset.block));
    });
    const search = document.querySelector('#wutm-block-search');
    const state = document.querySelector('#wutm-block-state');
    const filterRows = () => {
        const query = search.value.trim().toLowerCase();
        form.querySelectorAll('tbody tr').forEach(row => {
            const box = row.querySelector('[data-block]');
            const matchesState = !state.value || (box && (
                (state.value === 'hidden' && box.checked) ||
                (state.value === 'visible' && !box.checked) ||
                (state.value === 'unused' && box.dataset.count === '0')
            ));
            row.hidden = !matchesState || !row.textContent.toLowerCase().includes(query);
        });
    };
    search.addEventListener('input', filterRows);
    state.addEventListener('change', filterRows);
    form.addEventListener('change', filterRows);
    document.querySelector('#wutm-block-hide-unused').onclick = () => {
        boxes.filter(box => box.dataset.count === '0').forEach(box => { box.checked = true; });
        filterRows();
    };
    document.querySelector('#wutm-block-show-all').onclick = () => {
        boxes.forEach(box => { box.checked = false; });
        filterRows();
    };
    scan.onclick = async () => {
        scan.disabled = true;
        form.querySelector('button[name=wutm_save_blocks]').disabled = true;
        try {
            const body = new URLSearchParams({ action: 'wutm_gutenberg_scan', nonce: scan.dataset.nonce, restart: '1' });
            for (;;) {
                const response = await fetch(ajaxurl, { method: 'POST', body });
                const result = await response.json();
                if (!result.success) throw new Error('scan');
                status.textContent = '已掃描 ' + result.data.scanned + ' 筆內容';
                if (result.data.done) break;
                body.delete('restart');
            }
            location.replace(location.href);
        } catch (_) {
            status.textContent = '掃描未完成，請重試。既有結果已保留。';
            scan.disabled = false;
            form.querySelector('button[name=wutm_save_blocks]').disabled = false;
        }
    };
})();
