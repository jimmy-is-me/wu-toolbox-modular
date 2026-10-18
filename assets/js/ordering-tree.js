(function ($) {
    'use strict';
    $(function () {
        const root = document.getElementById('wutm-co-tree');
        if (!root) return;
        const search = root.querySelector('input[type="search"]');
        const save = root.querySelector('#wutm-tree-save');
        const status = root.querySelector('[role="status"]');
        const dirty = new Set();
        let busy = false;
        const lists = Array.from(root.querySelectorAll('.wutm-tree-list'));
        const mark = list => { dirty.add(list); save.disabled = false; status.textContent = '有未儲存的變更'; };
        // Tree replaces the old parent-by-parent picker, retaining the taxonomy selector.
        const parentPicker = document.querySelector('.wutm-co-picker [name="parent"]');
        if (parentPicker) parentPicker.closest('label').hidden = true;
        const hint = document.querySelector('.wutm-co-picker + .description');
        if (hint) hint.textContent = '下方一次顯示完整分類階層，不需逐一切換上層分類。';
        lists.forEach(list => $(list).sortable({
            items: '> li[data-id]', handle: '.wutm-tree-handle', axis: 'y',
            placeholder: 'wutm-co-placeholder', tolerance: 'pointer',
            update: () => mark(list)
        }));
        function filter() {
            const query = search.value.trim().toLocaleLowerCase();
            // Bottom-up keeps matching descendants and their path visible.
            Array.from(root.querySelectorAll('li[data-id]')).reverse().forEach(item => {
                const match = item.dataset.name.toLocaleLowerCase().includes(query);
                const childMatch = Array.from(item.querySelectorAll(':scope > ul > li')).some(child => !child.hidden);
                item.hidden = !!query && !match && !childMatch;
                const childList = item.querySelector(':scope > ul');
                const toggle = item.querySelector(':scope > div .wutm-tree-toggle');
                if (childList) childList.hidden = query ? false : toggle?.getAttribute('aria-expanded') === 'false';
            });
            lists.forEach(list => $(list).sortable('option', 'disabled', busy || !!query));
            root.querySelectorAll('[data-move]').forEach(button => { button.disabled = busy || !!query; });
        }
        search.addEventListener('input', filter);
        root.addEventListener('click', event => {
            const button = event.target.closest('button');
            if (!button || busy) return;
            if (button.matches('.wutm-tree-toggle')) {
                if (search.value.trim()) return;
                const expanded = button.getAttribute('aria-expanded') !== 'true';
                button.setAttribute('aria-expanded', String(expanded));
                button.closest('li').querySelector(':scope > ul').hidden = !expanded;
                button.textContent = expanded ? '▾' : '▸';
            }
            if (button.hasAttribute('data-tree-expand')) {
                search.value = '';
                const expanded = button.dataset.treeExpand === 'true';
                root.querySelectorAll('.wutm-tree-toggle').forEach(toggle => {
                    toggle.setAttribute('aria-expanded', String(expanded)); toggle.textContent = expanded ? '▾' : '▸';
                });
                filter();
            }
            if (button.dataset.move) {
                const item = button.closest('li'), list = item.parentElement;
                const other = button.dataset.move === 'up' ? item.previousElementSibling : item.nextElementSibling;
                if (!other) return;
                if (button.dataset.move === 'up') list.insertBefore(item, other); else list.insertBefore(other, item);
                mark(list); button.focus();
            }
        });
        save.addEventListener('click', async () => {
            if (busy || !dirty.size) return;
            busy = true; save.disabled = true;
            root.querySelectorAll('input,button').forEach(field => { field.disabled = true; });
            lists.forEach(list => $(list).sortable('disable'));
            try {
                // Save only modified sibling groups, sequentially; never reparent terms.
                for (const list of Array.from(dirty)) {
                    status.textContent = '儲存中，剩餘 ' + dirty.size + ' 組…';
                    const response = await $.ajax({url: ajaxurl, method: 'POST', timeout: 20000, data: {
                        action: 'wutm_content_ordering_terms', nonce: window.WUTMNativeOrdering.nonce,
                        taxonomy: root.dataset.object, parent: list.dataset.parent,
                        ids: Array.from(list.children).map(item => item.dataset.id)
                    }});
                    if (!response?.success) throw new Error(response?.data?.message || '儲存失敗');
                    dirty.delete(list);
                }
                status.textContent = '分類順序已儲存';
            } catch (error) { status.textContent = '部分排序尚未儲存，請重試：' + (error.message || '連線失敗'); }
            finally {
                busy = false;
                root.querySelectorAll('input,button').forEach(field => { field.disabled = false; });
                save.disabled = !dirty.size; filter();
            }
        });
        window.addEventListener('beforeunload', event => { if (dirty.size) { event.preventDefault(); event.returnValue = ''; } });
    });
})(jQuery);
