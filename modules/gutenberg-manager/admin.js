(() => {
    const form = document.querySelector('#wutm-block-settings');
    if (!form) return;
    const boxes = [...form.querySelectorAll('[data-block]')];
    const save = form.elements.wutm_save_blocks;
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
        save.disabled = true;
        try {
            const body = new URLSearchParams({ action: 'wutm_gutenberg_scan', nonce: scan.dataset.nonce, restart: '1' });
            for (;;) {
                const response = await fetch(ajaxurl, { method: 'POST', body });
                const result = await response.json();
                if (!result.success) throw new Error('scan');
                status.textContent = '已掃描 ' + result.data.scanned + ' 筆內容';
                if (result.data.done) {
                    const tbody = form.querySelector('tbody');
                    const counts = result.data.counts;
                    const known = new Set([...tbody.rows].map(row => row.dataset.blockName));
                    Object.keys(counts).filter(name => !known.has(name)).forEach(name => {
                        const row = tbody.insertRow();
                        row.dataset.blockName = name;
                        row.insertCell().append(document.createElement('code'));
                        row.cells[0].firstChild.textContent = name;
                        row.insertCell().textContent = name.split('/')[0];
                        row.insertCell().setAttribute('data-usage', '');
                        row.insertCell().textContent = '未註冊';
                    });
                    const rows = [...tbody.rows];
                    const count = row => Object.hasOwn(counts, row.dataset.blockName) ? counts[row.dataset.blockName] : 0;
                    rows.sort((a, b) => count(b) - count(a) || (a.dataset.blockName < b.dataset.blockName ? -1 : 1));
                    rows.forEach(row => {
                        row.querySelector('[data-usage]').textContent = count(row);
                        const box = row.querySelector('[data-block]');
                        if (box) box.dataset.count = count(row);
                        tbody.append(row);
                    });
                    form.querySelectorAll('th')[2].setAttribute('aria-sort', 'descending');
                    document.querySelector('#wutm-block-scan-time').textContent = '上次掃描：' + result.data.timestamp;
                    document.querySelector('#wutm-block-hide-unused').disabled = false;
                    filterRows();
                    status.textContent = '掃描完成，共 ' + result.data.scanned + ' 筆內容';
                    break;
                }
                body.delete('restart');
            }
        } catch (_) {
            status.textContent = '掃描未完成，請重試。既有結果已保留。';
        } finally {
            scan.disabled = false;
            save.disabled = false;
        }
    };
})();
