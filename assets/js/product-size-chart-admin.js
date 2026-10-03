(function () {
    'use strict';
    var root = document.querySelector('.wutm-size-chart-admin');
    if (!root) return;
    var columns = root.querySelector('#wutm-size-chart-columns');
    var rows = root.querySelector('#wutm-size-chart-rows');
    var columnIndex = 0, imageFrame;
    function element(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text) node.textContent = text;
        return node;
    }
    function keys() { return Array.from(columns.children).map(function (node) { return node.dataset.columnKey; }); }
    function cell(key, value, label) {
        var node = element('label'); node.dataset.columnKey = key;
        node.appendChild(element('span', 'wutm-sc-cell-label', label || '新規格'));
        var input = element('input'); input.type = 'text'; input.value = value || '';
        node.appendChild(input); return node;
    }
    function reindex() {
        Array.from(rows.children).forEach(function (row, index) {
            row.querySelector('.wutm-sc-row-number').textContent = index + 1;
            row.querySelectorAll('[data-column-key] input').forEach(function (input) { input.name = 'size_chart_rows[' + index + '][' + input.parentElement.dataset.columnKey + ']'; });
        });
    }
    function columnLabel(key) {
        var node = Array.from(columns.children).find(function (column) { return column.dataset.columnKey === key; });
        return node ? node.querySelector('input').value : '';
    }
    root.querySelector('#wutm-size-chart-add-row').addEventListener('click', function () {
        var row = element('fieldset', 'wutm-sc-row');
        var legend = element('legend', '', '規格資料 '); legend.appendChild(element('span', 'wutm-sc-row-number')); row.appendChild(legend);
        var grid = element('div', 'wutm-sc-grid');
        keys().forEach(function (key) { grid.appendChild(cell(key, '', columnLabel(key))); }); row.appendChild(grid);
        var remove = element('button', 'button-link-delete wutm-size-chart-remove-row', '移除此列'); remove.type = 'button'; row.appendChild(remove);
        rows.appendChild(row); reindex();
    });
    root.querySelector('#wutm-size-chart-add-column').addEventListener('click', function () {
        var key = 'custom_' + Date.now() + '_' + (++columnIndex);
        var node = element('div', 'wutm-size-chart-column'); node.dataset.columnKey = key;
        var label = element('label', '', '欄位名稱'); label.htmlFor = 'wutm-size-chart-label-' + key; node.appendChild(label);
        var input = element('input'); input.type = 'text'; input.id = label.htmlFor; input.name = 'size_chart_columns[' + key + '][label]'; input.placeholder = '欄位名稱'; input.required = true; node.appendChild(input);
        var remove = element('button', 'button-link-delete wutm-size-chart-remove-column', '移除欄位'); remove.type = 'button'; node.appendChild(remove); columns.appendChild(node);
        Array.from(rows.children).forEach(function (row) { row.querySelector('.wutm-sc-grid').appendChild(cell(key)); }); reindex(); input.focus();
    });
    columns.addEventListener('input', function (event) {
        if (!event.target.matches('input')) return;
        var key = event.target.parentElement.dataset.columnKey;
        rows.querySelectorAll('[data-column-key]').forEach(function (node) { if (node.dataset.columnKey === key) node.querySelector('.wutm-sc-cell-label').textContent = event.target.value || '新規格'; });
    });
    root.addEventListener('click', function (event) {
        var removeColumn = event.target.closest('.wutm-size-chart-remove-column');
        if (removeColumn) {
            if (keys().length <= 1) { window.alert('至少保留一個規格欄位。'); return; }
            if (!window.confirm('移除此欄位及各列對應資料？更新商品後才會儲存變更。')) return;
            var column = removeColumn.parentElement, key = column.dataset.columnKey;
            column.remove(); rows.querySelectorAll('[data-column-key]').forEach(function (node) { if (node.dataset.columnKey === key) node.remove(); });
        }
        var removeRow = event.target.closest('.wutm-size-chart-remove-row');
        if (removeRow && window.confirm('移除此資料列？更新商品後才會儲存變更。')) { removeRow.closest('.wutm-sc-row').remove(); reindex(); }
    });
    root.querySelector('#wutm-size-chart-select-image').addEventListener('click', function () {
        if (!window.wp || !wp.media) return;
        if (!imageFrame) {
            imageFrame = wp.media({ title: '選擇規格表圖片', button: { text: '使用此圖片' }, library: { type: 'image' }, multiple: false });
            imageFrame.on('select', function () {
                var item = imageFrame.state().get('selection').first().toJSON();
                root.querySelector('#wutm-size-chart-image-id').value = item.id;
                var image = element('img'); image.alt = ''; image.src = item.sizes && item.sizes.medium ? item.sizes.medium.url : item.url;
                root.querySelector('#wutm-size-chart-image-preview').replaceChildren(image);
                root.querySelector('#wutm-size-chart-remove-image').hidden = false;
            });
        }
        imageFrame.open();
    });
    root.querySelector('#wutm-size-chart-remove-image').addEventListener('click', function () {
        root.querySelector('#wutm-size-chart-image-id').value = '';
        root.querySelector('#wutm-size-chart-image-preview').replaceChildren(); this.hidden = true;
    });
    reindex();
})();
