(function ($) {
    'use strict';
    const dialog = document.getElementById('wu-seo-dialog');
    if (!dialog) return;
    const form = document.getElementById('wu-seo-editor');
    const status = document.getElementById('wu-seo-save-status');
    let row = null;
    let busy = false;
    function preview() {
        ['title', 'description', 'og_title', 'og_desc'].forEach(key => {
            const field = form.elements[key];
            let output = document.getElementById('wu-seo-length-' + key);
            if (!output) { output = document.createElement('small'); output.id = 'wu-seo-length-' + key; field.parentNode.appendChild(output); }
            output.textContent = '目前 ' + Array.from(field.value || '').length + ' 字（長度僅供編輯參考）';
        });
        document.getElementById('wu-seo-search-title').textContent = form.elements.title.value || (row && row.title) || '';
        document.getElementById('wu-seo-search-desc').textContent = form.elements.description.value || (row && row.description) || '';
        document.getElementById('wu-seo-social-preview').textContent = (form.elements.og_title.value || form.elements.title.value || (row && row.title) || '') + ' — ' + (form.elements.og_desc.value || form.elements.description.value || (row && row.description) || '');
    }
    function refreshRow() {
        const tr = document.getElementById('post-' + form.elements.id.value);
        if (!tr) return;
        const values = {title: row.title, description: row.description, topic: row.data.topic,
            canonical: row.data.canonical || row.url || '', noindex: Number(row.data.noindex) ? 'noindex' : '允許索引（不保證收錄）', modified: row.modified || '尚無修改記錄'};
        Object.entries(values).forEach(([key, value]) => {
            const cell = tr.querySelector('.column-wu_seo_' + key);
            if (cell) cell.textContent = value || '';
        });
        const source = tr.querySelector('.wu-seo-row-data');
        if (source) source.dataset.seo = JSON.stringify(row.data);
        const cell = tr.querySelector('.column-wu_seo');
        if (cell) {
            Array.from(cell.childNodes).filter(node => node.nodeType === Node.TEXT_NODE).forEach(node => node.remove());
            const label = Number(row.data.noindex) ? '不建立索引' : (row.data.title || row.data.description ? '已手動設定' : '使用自動範本');
            cell.prepend(document.createTextNode(label + ((row.issues || []).length ? '；' + row.issues.join('；') : '')));
        }
    }
    async function request(extra) {
        if (busy) return;
        busy = true;
        form.querySelector('[type=submit]').disabled = true;
        status.textContent = '處理中…';
        try {
            const result = await $.post(wuSeoManagement.url, Object.assign({action: 'wu_seo_edit', nonce: wuSeoManagement.nonce, id: form.elements.id.value}, extra));
            if (!result.success) throw new Error(typeof result.data === 'string' ? result.data : '儲存失敗');
            row = result.data;
            Object.keys(row.data).forEach(key => {
                const field = form.elements[key];
                if (!field) return;
                if (field.type === 'checkbox') field.checked = Number(row.data[key]) === 1;
                else field.value = row.data[key];
            });
            const history = document.getElementById('wu-seo-history');
            history.replaceChildren();
            row.history.slice().reverse().forEach((entry, reverseIndex) => {
                const p = document.createElement('p');
                p.textContent = entry.time + ' · 使用者 ' + entry.user + ' · ' + entry.field + '：' + entry.before + ' → ' + entry.after + ' ';
                const button = document.createElement('button'); button.type = 'button'; button.className = 'button'; button.textContent = '還原此欄位';
                button.addEventListener('click', () => { if (window.confirm('還原這個 SEO 欄位？正文不會改動。')) request({restore: row.history.length - 1 - reverseIndex, confirm: 1}); });
                p.appendChild(button); history.appendChild(p);
            });
            const image = document.getElementById('wu-seo-social-image');
            image.hidden = !row.image; if (row.image) image.src = row.image;
            preview();
            refreshRow();
            status.textContent = extra && (extra.data || extra.restore !== undefined) ? '已儲存，列表已同步更新。' : '已讀取。';
        } catch (error) { status.textContent = error.message || '連線失敗，請重試。'; }
        finally { busy = false; form.querySelector('[type=submit]').disabled = false; }
    }
    document.addEventListener('click', event => {
        const button = event.target.closest('.wu-seo-open');
        if (!button || busy) return;
        form.reset(); row = null; form.elements.id.value = button.dataset.id;
        if (dialog.showModal) dialog.showModal(); else dialog.setAttribute('open', '');
        request({});
    });
    document.getElementById('wu-seo-close').addEventListener('click', () => { if (!busy) dialog.close(); });
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    form.addEventListener('input', preview);
    form.addEventListener('submit', event => {
        event.preventDefault(); if (busy) return;
        const data = {};
        Array.from(form.elements).forEach(field => { if (field.name && field.name !== 'id') data[field.name] = field.type === 'checkbox' ? (field.checked ? 1 : 0) : field.value; });
        request({data});
    });
    // Load native Quick Edit from the row, including after another row was edited.
    const original = inlineEditPost.edit;
    inlineEditPost.edit = function (id) {
        original.apply(this, arguments);
        const postId = typeof id === 'object' ? this.getId(id) : id;
        const source = document.querySelector('#post-' + postId + ' .wu-seo-row-data');
        const edit = document.getElementById('edit-' + postId);
        if (!source || !edit) return;
        let data; try { data = JSON.parse(source.dataset.seo); } catch (_) { return; }
        Object.keys(data).forEach(key => {
            const field = edit.querySelector('[name="wu_seo_quick[' + key + ']"]');
            if (!field) return;
            if (field.type === 'checkbox') field.checked = Number(data[key]) === 1; else field.value = data[key];
        });
    };
})(jQuery);
